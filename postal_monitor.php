<?php

require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class PostalMonitor
{
    private $db;
    private $config;
    private $stateFile;

    public function __construct($configFile = null)
    {
        $this->loadConfig($configFile);
        $this->connectDatabase();
        $this->stateFile = $this->config['monitoring']['state_file_path'] ?? './last_checked_delivery_id';
    }

    private function loadConfig($configFile = null)
    {
        // Try different config file locations
        $configFiles = [
            $configFile,
            '/etc/postal-monitor/config.ini',
            __DIR__ . '/config.ini'
        ];
        
        $configPath = null;
        foreach ($configFiles as $file) {
            if ($file && file_exists($file)) {
                $configPath = $file;
                break;
            }
        }
        
        if (!$configPath) {
            die("Configuration file not found. Please check config.ini exists.\n");
        }
        
        $this->config = parse_ini_file($configPath, true);
        if ($this->config === false) {
            die("Failed to parse configuration file: $configPath\n");
        }
        
        // Validate required sections
        $required = ['database', 'smtp', 'notifications', 'monitoring'];
        foreach ($required as $section) {
            if (!isset($this->config[$section])) {
                die("Missing required configuration section: $section\n");
            }
        }
    }

    private function log($message, $isError = false)
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message\n";
        
        if ($isError) {
            // Write to stderr for errors
            fwrite(STDERR, $logMessage);
        } else {
            // Write to stdout for normal messages
            echo $logMessage;
        }
    }

    private function connectDatabase()
    {
        try {
            $dbConfig = $this->config['database'];
            $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['name']};charset=utf8mb4";
            $this->db = new PDO($dsn, $dbConfig['user'], $dbConfig['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            $this->log("Connected to database successfully");
        } catch (PDOException $e) {
            $errorMsg = "Database connection failed: " . $e->getMessage();
            $this->log($errorMsg, true);
            die("$errorMsg\n");
        }
    }

    private function getLastCheckedDeliveryId()
    {
        if (file_exists($this->stateFile)) {
            return intval(trim(file_get_contents($this->stateFile)));
        }
        return 0;
    }

    private function saveLastCheckedDeliveryId($id)
    {
        file_put_contents($this->stateFile, $id);
    }

    public function checkForFailedDeliveries()
    {
        $lastCheckedId = $this->getLastCheckedDeliveryId();
        
        $this->log("Checking for failed deliveries since ID: $lastCheckedId");

        // Query for failed deliveries that are newer than our last check
        $sql = "
            SELECT 
                d.id,
                d.message_id,
                d.status,
                d.code,
                d.output,
                d.details,
                d.timestamp,
                m.rcpt_to,
                m.mail_from,
                m.subject,
                m.scope
            FROM deliveries d
            JOIN messages m ON d.message_id = m.id
            WHERE d.id > :last_checked_id
            AND d.status NOT IN ('Sent', 'SoftFail')
            AND m.scope = 'outgoing'
            ORDER BY d.id ASC
        ";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['last_checked_id' => $lastCheckedId]);
            $failures = $stmt->fetchAll();

            if (empty($failures)) {
                $this->log("No new delivery failures found");
                return;
            }

            $this->log("Found " . count($failures) . " new delivery failure(s)");

            foreach ($failures as $failure) {
                $this->sendNotification($failure);
                $this->saveLastCheckedDeliveryId($failure['id']);
            }

        } catch (PDOException $e) {
            $this->log("Database error: " . $e->getMessage(), true);
        }
    }

    private function sendNotification($failure)
    {
        $mail = new PHPMailer(true);

        try {
            $smtpConfig = $this->config['smtp'];
            $notifConfig = $this->config['notifications'];
            
            // Server settings
            $mail->isSMTP();
            $mail->Host = $smtpConfig['host'];
            $mail->SMTPAuth = !empty($smtpConfig['user']);
            $mail->Username = $smtpConfig['user'];
            $mail->Password = $smtpConfig['password'];
            $mail->SMTPSecure = filter_var($smtpConfig['use_tls'], FILTER_VALIDATE_BOOLEAN) ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = $smtpConfig['port'];

            // Recipients
            $mail->setFrom($notifConfig['from_email'], 'Postal Monitor');
            $mail->addAddress($notifConfig['email']);

            // Content
            $mail->isHTML(true);
            $mail->Subject = "Postal Delivery Failure Alert - {$failure['status']}";
            
            $htmlBody = $this->generateEmailBody($failure);
            $mail->Body = $htmlBody;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

            $mail->send();
            $this->log("Notification sent for delivery ID: {$failure['id']}");

        } catch (Exception $e) {
            $this->log("Failed to send notification: {$mail->ErrorInfo}", true);
        }
    }

    private function generateEmailBody($failure)
    {
        $timestamp = date('Y-m-d H:i:s', $failure['timestamp']);
        
        return "
        <h2>Postal Delivery Failure Alert</h2>
        <p><strong>A delivery failure has been detected in your Postal mail server.</strong></p>
        
        <h3>Delivery Details:</h3>
        <ul>
            <li><strong>Delivery ID:</strong> {$failure['id']}</li>
            <li><strong>Message ID:</strong> {$failure['message_id']}</li>
            <li><strong>Status:</strong> {$failure['status']}</li>
            <li><strong>Timestamp:</strong> {$timestamp}</li>
        </ul>
        
        <h3>Message Details:</h3>
        <ul>
            <li><strong>From:</strong> {$failure['mail_from']}</li>
            <li><strong>To:</strong> {$failure['rcpt_to']}</li>
            <li><strong>Subject:</strong> {$failure['subject']}</li>
            <li><strong>Scope:</strong> {$failure['scope']}</li>
        </ul>
        
        <h3>Error Information:</h3>
        <ul>
            <li><strong>Error Code:</strong> " . ($failure['code'] ?: 'N/A') . "</li>
            <li><strong>Output:</strong> " . ($failure['output'] ?: 'N/A') . "</li>
            <li><strong>Details:</strong> " . ($failure['details'] ?: 'N/A') . "</li>
        </ul>
        
        <p><em>This is an automated notification from your Postal monitoring system.</em></p>
        ";
    }

    public function startContinuousMonitoring()
    {
        $interval = $this->config['monitoring']['check_interval_minutes'] ?? 5;
        $this->log("Starting continuous monitoring (check every $interval minutes)");
        $this->log("Press Ctrl+C to stop monitoring");

        while (true) {
            $this->checkForFailedDeliveries();
            sleep($interval * 60);
        }
    }
}

// CLI handling
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

// Check command line arguments
$options = getopt('c:', ['once', 'help', 'config:']);

if (isset($options['help'])) {
    echo "Usage: php postal_monitor.php [options]\n";
    echo "Options:\n";
    echo "  --once              Run a single check and exit\n";
    echo "  --config=FILE       Use specific config file\n";
    echo "  -c FILE             Use specific config file (short form)\n";
    echo "  --help              Show this help message\n";
    echo "\n";
    echo "Without options, runs in continuous monitoring mode.\n";
    exit(0);
}

$configFile = $options['config'] ?? $options['c'] ?? null;
$monitor = new PostalMonitor($configFile);

if (isset($options['once'])) {
    $monitor->checkForFailedDeliveries();
} else {
    $monitor->startContinuousMonitoring();
}
