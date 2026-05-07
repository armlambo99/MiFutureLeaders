<?php
/**
 * Contact Form Handler for Mi Future Leaders
 * 
 * This script processes the contact form submission, validates input,
 * and sends an email notification to the organization.
 */

// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Set to 0 in production
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.txt');

// Set JSON content type for AJAX responses
header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(false, 'Invalid request method. Please use the contact form.');
    exit;
}

// Email configuration
$toEmail = 'info@mifutureleaders.org';
$fromEmail = 'noreply@mifutureleaders.org';
$siteName = 'Mi Future Leaders';

// Sanitize and validate input data
$firstName = sanitizeInput($_POST['firstName'] ?? '');
$lastName = sanitizeInput($_POST['lastName'] ?? '');
$email = sanitizeInput($_POST['email'] ?? '');
$phone = sanitizeInput($_POST['phone'] ?? '');
$subject = sanitizeInput($_POST['subject'] ?? 'General Enquiry');
$message = sanitizeInput($_POST['message'] ?? '');

// Validation array
$errors = [];

// Validate First Name
if (empty($firstName)) {
    $errors[] = 'First name is required.';
} elseif (strlen($firstName) < 2) {
    $errors[] = 'First name must be at least 2 characters.';
} elseif (strlen($firstName) > 50) {
    $errors[] = 'First name cannot exceed 50 characters.';
} elseif (!preg_match('/^[a-zA-Z\s\-\'à-ÿ]+$/u', $firstName)) {
    $errors[] = 'First name contains invalid characters.';
}

// Validate Last Name
if (empty($lastName)) {
    $errors[] = 'Last name is required.';
} elseif (strlen($lastName) < 2) {
    $errors[] = 'Last name must be at least 2 characters.';
} elseif (strlen($lastName) > 50) {
    $errors[] = 'Last name cannot exceed 50 characters.';
} elseif (!preg_match('/^[a-zA-Z\s\-\'à-ÿ]+$/u', $lastName)) {
    $errors[] = 'Last name contains invalid characters.';
}

// Validate Email
if (empty($email)) {
    $errors[] = 'Email address is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please provide a valid email address.';
} elseif (strlen($email) > 100) {
    $errors[] = 'Email address cannot exceed 100 characters.';
}

// Validate Phone (optional but validate if provided)
if (!empty($phone)) {
    $phonePattern = '/^[\+\d\s\-\(\)]{8,20}$/';
    if (!preg_match($phonePattern, $phone)) {
        $errors[] = 'Please provide a valid phone number.';
    }
}

// Validate Subject
$validSubjects = [
    'General Enquiry',
    'Partnership Opportunity',
    'School Programme',
    'Donation Support',
    'Volunteer Interest',
    'Media/Press'
];
if (empty($subject)) {
    $errors[] = 'Please select a subject.';
} elseif (!in_array($subject, $validSubjects)) {
    $errors[] = 'Invalid subject selection.';
}

// Validate Message
if (empty($message)) {
    $errors[] = 'Message content is required.';
} elseif (strlen($message) < 10) {
    $errors[] = 'Message must be at least 10 characters.';
} elseif (strlen($message) > 5000) {
    $errors[] = 'Message cannot exceed 5000 characters.';
}

// Honeypot check for bot detection (hidden field)
$honeypot = $_POST['website'] ?? '';
if (!empty($honeypot)) {
    // This is likely a bot - return success without processing
    sendJsonResponse(true, 'Your message has been received.');
    exit;
}

// Rate limiting - prevent spam (optional)
session_start();
$rateLimitKey = 'contact_form_' . $_SERVER['REMOTE_ADDR'];
$currentTime = time();
$windowStart = $currentTime - 3600; // 1 hour window

if (isset($_SESSION[$rateLimitKey])) {
    $submissions = array_filter($_SESSION[$rateLimitKey], function($timestamp) use ($windowStart) {
        return $timestamp > $windowStart;
    });
    
    if (count($submissions) >= 5) { // Max 5 submissions per hour
        sendJsonResponse(false, 'You have exceeded the submission limit. Please try again later.');
        exit;
    }
} else {
    $submissions = [];
}

// Check for duplicate submissions (within last 5 minutes)
$duplicateKey = 'last_submission_' . md5($email);
if (isset($_SESSION[$duplicateKey]) && ($currentTime - $_SESSION[$duplicateKey]) < 300) {
    sendJsonResponse(false, 'Please wait a few minutes before submitting another message.');
    exit;
}

// If validation passed, send the email
if (empty($errors)) {
    // Record this submission for rate limiting
    $submissions[] = $currentTime;
    $_SESSION[$rateLimitKey] = $submissions;
    $_SESSION[$duplicateKey] = $currentTime;
    
    $emailSent = sendContactEmail($firstName, $lastName, $email, $phone, $subject, $message, $toEmail, $siteName);
    
    if ($emailSent) {
        // Also send an auto-reply to the user
        sendAutoReply($email, $firstName, $siteName);
        
        sendJsonResponse(true, 'Thank you! Your message has been sent successfully. We\'ll respond within 24 hours.');
    } else {
        sendJsonResponse(false, 'Sorry, there was an error sending your message. Please try again or contact us directly by phone.');
        
        // Log the error for debugging
        error_log("Contact form email failed for: $email");
    }
} else {
    // Return validation errors
    sendJsonResponse(false, implode(' ', $errors));
}

/**
 * Sanitize input data to prevent XSS and injection attacks
 * 
 * @param string $input The raw input string
 * @return string Sanitized string
 */
function sanitizeInput($input) {
    $input = trim($input);
    $input = stripslashes($input);
    $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    return $input;
}

/**
 * Send the contact notification email to the organization
 * 
 * @param string $firstName Sender's first name
 * @param string $lastName Sender's last name
 * @param string $email Sender's email address
 * @param string $phone Sender's phone number
 * @param string $subject Message subject
 * @param string $message Message content
 * @param string $toEmail Recipient email
 * @param string $siteName Site name for branding
 * @return bool True if email sent successfully
 */
function sendContactEmail($firstName, $lastName, $email, $phone, $subject, $message, $toEmail, $siteName) {
    $fullName = $firstName . ' ' . $lastName;
    $currentDate = date('F j, Y \a\t g:i A');
    
    // Email subject
    $emailSubject = "[Contact Form] $subject from $fullName";
    
    // HTML email body
    $htmlContent = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #2D3A2B; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
            .header h2 { color: #C6A43F; margin: 0; font-family: 'Cinzel', serif; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
            .field { margin-bottom: 15px; }
            .field-label { font-weight: bold; color: #2D3A2B; border-left: 3px solid #C6A43F; padding-left: 10px; margin-bottom: 5px; }
            .field-value { padding-left: 13px; color: #555; }
            .message-box { background: white; padding: 15px; border-radius: 8px; border: 1px solid #e0e0e0; margin-top: 10px; }
            .footer { text-align: center; padding: 20px; font-size: 12px; color: #888; border-top: 1px solid #eee; margin-top: 20px; }
            .badge { display: inline-block; background: #C6A43F20; color: #C6A43F; padding: 5px 10px; border-radius: 20px; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>⚜️ $siteName</h2>
                <p style='color: #fff; margin: 5px 0 0;'>New Contact Form Submission</p>
            </div>
            <div class='content'>
                <p><strong>Submitted on:</strong> $currentDate</p>
                <div class='field'>
                    <div class='field-label'>📋 From</div>
                    <div class='field-value'>$fullName</div>
                </div>
                <div class='field'>
                    <div class='field-label'>✉️ Email</div>
                    <div class='field-value'><a href='mailto:$email'>$email</a></div>
                </div>
                <div class='field'>
                    <div class='field-label'>📞 Phone</div>
                    <div class='field-value'>" . (!empty($phone) ? "<a href='tel:$phone'>$phone</a>" : 'Not provided') . "</div>
                </div>
                <div class='field'>
                    <div class='field-label'>🏷️ Subject</div>
                    <div class='field-value'><span class='badge'>$subject</span></div>
                </div>
                <div class='field'>
                    <div class='field-label'>💬 Message</div>
                    <div class='message-box'>" . nl2br(htmlspecialchars($message)) . "</div>
                </div>
            </div>
            <div class='footer'>
                <p>This message was sent from the contact form on $siteName website.</p>
                <p>IP Address: " . $_SERVER['REMOTE_ADDR'] . "</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // Plain text version for email clients that don't support HTML
    $textContent = "
    $siteName - New Contact Form Submission
    ========================================
    
    Submitted on: $currentDate
    
    From: $fullName
    Email: $email
    Phone: " . (!empty($phone) ? $phone : 'Not provided') . "
    Subject: $subject
    
    Message:
    ----------------------------------------
    $message
    ----------------------------------------
    
    IP Address: " . $_SERVER['REMOTE_ADDR'] . "
    ";
    
    // Email headers
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: "' . $siteName . ' Contact" <noreply@' . $_SERVER['HTTP_HOST'] . '>',
        'Reply-To: ' . $email,
        'X-Mailer: PHP/' . phpversion(),
        'X-Priority: 3',
        'X-Originating-IP: ' . $_SERVER['REMOTE_ADDR']
    ];
    
    // For better deliverability, use wordwrap for text version as alternative
    $boundary = md5(time());
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
    
    $body = "--$boundary\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $body .= $textContent . "\r\n\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $body .= $htmlContent . "\r\n\r\n";
    $body .= "--$boundary--";
    
    return mail($toEmail, $emailSubject, $body, implode("\r\n", $headers));
}

/**
 * Send an auto-reply confirmation to the user
 * 
 * @param string $userEmail User's email address
 * @param string $firstName User's first name
 * @param string $siteName Site name
 * @return bool True if email sent successfully
 */
function sendAutoReply($userEmail, $firstName, $siteName) {
    $subject = "Thank you for contacting $siteName";
    
    $htmlContent = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 500px; margin: 0 auto; padding: 20px; }
            .header { background: #2D3A2B; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
            .header h2 { color: #C6A43F; margin: 0; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
            .signature { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; color: #666; }
            .btn { display: inline-block; background: #C6A43F; color: #2D3A2B; padding: 10px 20px; text-decoration: none; border-radius: 5px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>⚜️ $siteName</h2>
            </div>
            <div class='content'>
                <h3>Dear $firstName,</h3>
                <p>Thank you for reaching out to <strong>$siteName</strong>. We have received your message and truly appreciate your interest in our work.</p>
                <p>Our team is committed to responding to all enquiries within <strong>24 hours</strong> during business days. Someone will be in touch with you shortly.</p>
                <p>In the meantime, you can:</p>
                <ul>
                    <li>📖 Learn more about our <a href='#' style='color: #C6A43F;'>programmes and services</a></li>
                    <li>🤝 Explore <a href='#' style='color: #C6A43F;'>partnership opportunities</a></li>
                    <li>💚 Support our mission through <a href='#' style='color: #C6A43F;'>donations</a></li>
                </ul>
                <p>If your matter is urgent, please don't hesitate to call us directly at <strong>+27 82 303 6050</strong>.</p>
                <div class='signature'>
                    <p>With gratitude,<br>
                    <strong>The $siteName Team</strong><br>
                    <em>Empowering young minds through trauma-informed mental health education</em></p>
                </div>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $textContent = "
    Dear $firstName,
    
    Thank you for reaching out to $siteName. We have received your message and truly appreciate your interest in our work.
    
    Our team is committed to responding to all enquiries within 24 hours during business days. Someone will be in touch with you shortly.
    
    If your matter is urgent, please don't hesitate to call us directly at +27 82 303 6050.
    
    With gratitude,
    The $siteName Team
    Empowering young minds through trauma-informed mental health education
    ";
    
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: "' . $siteName . '" <info@mifutureleaders.org>',
        'Reply-To: info@mifutureleaders.org',
        'X-Mailer: PHP/' . phpversion()
    ];
    
    $boundary = md5(time());
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
    
    $body = "--$boundary\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $body .= $textContent . "\r\n\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $body .= $htmlContent . "\r\n\r\n";
    $body .= "--$boundary--";
    
    return mail($userEmail, $subject, $body, implode("\r\n", $headers));
}

/**
 * Send JSON response back to the client
 * 
 * @param bool $success Whether the operation was successful
 * @param string $message Response message
 */
function sendJsonResponse($success, $message) {
    echo json_encode([
        'success' => $success,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE);
    exit;
}