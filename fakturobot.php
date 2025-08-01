<?php
echo "=== FakturoBot starting ===\n";
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpMimeMailParser\Parser;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// === Funkcje pomocnicze ===

function get_attachments_from_email($rawEmail) {
    $parser = new Parser();
    $parser->setText($rawEmail);
    $attachments = [];

    foreach ($parser->getAttachments() as $attachment) {
        $filename = $attachment->getFilename() ?: ('attachment_' . time() . '.bin');
        $filepath = TMP_DIR . "/" . time() . "_" . $filename;
        file_put_contents($filepath, $attachment->getContent());
        echo "    → Attachment saved ($filename), size: " . strlen($attachment->getContent()) . " bytes\n";
        $attachments[] = $filepath;
    }

    return $attachments;
}

function analyze_with_openai($text) {
    $url = "https://api.openai.com/v1/chat/completions";
    $headers = [
        "Content-Type: application/json",
        "Authorization: Bearer " . OPENAI_API_KEY
    ];
    $prompt = "Jesteś asystentem księgowym. Powiedz TAK, jeśli tekst dotyczy faktury (sprzedażowej lub kosztowej). Jeśli TAK, podaj:
            - numer faktury,
            - NIP wystawcy,
            - kwotę brutto.
            Jeśli to nie faktura, powiedz NIE. 
            Tekst dokumentu:
            $text";

    $data = [
        "model" => OPENAI_MODEL,
        "messages" => [
            ["role" => "system", "content" => "Jesteś pomocnikiem do klasyfikacji dokumentów."],
            ["role" => "user", "content" => $prompt]
        ],
        "temperature" => 0
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    $response = json_decode($result, true);
    return $response['choices'][0]['message']['content'] ?? '';
}

function log_to_db($db, $mail_subject, $filename, $analysis, $status) {
    $stmt = $db->prepare("INSERT INTO logs (date, subject, filename, analysis, status) VALUES (datetime('now'), ?, ?, ?, ?)");
    $stmt->execute([$mail_subject, $filename, $analysis, $status]);
}

function pdf_to_text($file) {
    $output = shell_exec("pdftotext " . escapeshellarg($file) . " -");
    if (trim($output) != '') return $output;

    $tmp_base = TMP_DIR . "/" . uniqid("pdfocr_");
    shell_exec("pdftoppm -png " . escapeshellarg($file) . " " . escapeshellarg($tmp_base));
    $ocr_text = '';
    foreach (glob($tmp_base . "-*.png") as $img) {
        $txt_file = $img . ".txt";
        shell_exec("tesseract " . escapeshellarg($img) . " " . escapeshellarg($img) . " -l pol+eng");
        if (file_exists($txt_file)) {
            $ocr_text .= file_get_contents($txt_file) . "\n";
        }
    }
    return $ocr_text;
}

function image_to_text($file) {
    $tmp_txt = $file . ".txt";
    shell_exec("tesseract " . escapeshellarg($file) . " " . escapeshellarg($file) . " -l pol+eng");
    return file_exists($tmp_txt) ? file_get_contents($tmp_txt) : '';
}



function forward_email($file, $filename) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // lub zmień na 'ssl' jeśli używasz portu 465
        $mail->Port = 587; // zmień na 465 jeśli używasz SSL

        $mail->CharSet = 'UTF-8';          
        $mail->Encoding = 'base64'; 

        $mail->setFrom(SMTP_USER, 'FakturoBot');
        $mail->addAddress(FORWARD_TO);
        $mail->Subject = "Invoice: $filename";
        $mail->Body = "Forwarding invoice as attachment.";
        $mail->addAttachment($file, $filename);

        $mail->send();
        echo "    → Mail was sent.\n";
    } catch (Exception $e) {
        echo "    !! Error sending mail: {$mail->ErrorInfo}\n";
    }
}

// === Start ===
$db = new PDO("sqlite:" . DB_FILE);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$inbox = imap_open(IMAP_HOST, IMAP_USER, IMAP_PASS) or die('Cannot connect to IMAP: ' . imap_last_error());
$emails = imap_search($inbox, 'UNSEEN');

if (!$emails) {
    echo "Now new messages.\n=== End of scan ===\n";
    imap_close($inbox);
    exit;
}

echo "Found " . count($emails) . " new messages.\n";

foreach ($emails as $num) {
    $header = imap_headerinfo($inbox, $num);
    $subject = isset($header->subject) ? imap_utf8($header->subject) : "(bez tematu)";
    echo "Processing message: {$subject}\n";

    // Pobieramy pełny RAW email
    $rawEmail = imap_fetchbody($inbox, $num, "", FT_PEEK);
    $attachments = get_attachments_from_email($rawEmail);

    if (!$attachments) {
        echo "    → No attachment\n";
        continue;
    }

    foreach ($attachments as $filepath) {
        $text = '';
        if (preg_match('/\.pdf$/i', $filepath)) {
            echo "    → Converting PDF to text\n";
            $text = pdf_to_text($filepath);
        } elseif (preg_match('/\.(jpg|jpeg|png)$/i', $filepath)) {
            echo "    → OCR of image\n";
            $text = image_to_text($filepath);
        } else {
            echo "    → Skiping (nieobsługiwany format)\n";
            continue;
        }

        echo "    → Analizing text by OpenAI...\n";
        $analysis = analyze_with_openai(substr($text, 0, 3000));
        echo "      Alalysis result: " . substr($analysis, 0, 80) . "...\n";

        if (stripos($analysis, "TAK") === 0) {
            echo "    → This is invoice. Forwarding.\n";
            forward_email($filepath, basename($filepath));
            log_to_db($db, $subject, basename($filepath), $analysis, 'FORWARDED');
        } else {
            echo "    → Seems not to be invoice. Marked as ignored.\n";
            log_to_db($db, $subject, basename($filepath), $analysis, 'IGNORED');
        }
    }

    imap_setflag_full($inbox, $num, "\\Seen");
}
imap_close($inbox);
echo "=== Done ===\n";
