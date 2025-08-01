# FakturoBot – AI-Powered Invoice Mail Agent

FakturoBot is a lightweight PHP-based agent designed to automatically process incoming emails, detect invoice attachments, and forward them to a configured email address (e.g., your accountant).  
It uses OpenAI for document classification and Tesseract OCR for text extraction from image-based PDFs.

## Features
- Connects to an IMAP inbox and checks for new emails
- Extracts PDF/JPG/PNG attachments
- Converts PDFs to text (with OCR fallback if needed)
- Uses OpenAI to classify whether the attachment is an invoice
- Forwards detected invoices to a configured email address
- Logs all actions in a local SQLite database
- Cron-friendly – can run periodically

## Requirements
- PHP 8.1+
- Composer
- SQLite
- Tesseract OCR (`tesseract`, with `pol` and `eng` language packs)
- Poppler (`pdftotext`, `pdftoppm` utilities)
- OpenAI API key
- SMTP credentials for forwarding

## Installation
git clone https://github.com/romekf/invoice-mail-agent.git
cd fakturobot
composer install

## Configuration
Copy config.php.example to config.php and update:
define('IMAP_HOST', '{imap.example.com:993/imap/ssl}INBOX');
define('IMAP_USER', 'your-email@example.com');
define('IMAP_PASS', 'your-password');

define('SMTP_HOST', 'smtp.example.com');
define('SMTP_USER', 'your-email@example.com');
define('SMTP_PASS', 'your-smtp-password');
define('FORWARD_TO', 'accounting@example.com');

define('OPENAI_API_KEY', 'your-openai-api-key');
define('OPENAI_MODEL', 'gpt-4o-mini');

Get your API key here: https://platform.openai.com/account/api-keys

# First run
Initialize databse
php init_db.php

## Usage
Run the agent manually:
php fakturobot.php

or schedule it with cron (e.g. every 10 minutes)
*/10 * * * * /usr/bin/php /path/to/fakturobot/fakturobot.php >> /var/log/fakturobot.log 2>&1

## Roadmap
- Web dashboard for log viewing
- Multi-inbox support
- Configurable filtering rules