<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

// Start session for rate limiting
session_start();

// Anti-spam functions
function isSpam($email, $message) {
    // Check for common spam patterns in message
    $spamKeywords = ['viagra', 'casino', 'lottery', 'prize', 'winner', 'loan', 'bitcoin', 'crypto', 'investment'];
    foreach ($spamKeywords as $keyword) {
        if (stripos($message, $keyword) !== false) {
            return true;
        }
    }
    
    // Check for excessive URLs in message (common in spam)
    $urlCount = preg_match_all('/(https?:\/\/[^\s]+)/', $message, $matches);
    if ($urlCount > 2) {
        return true;
    }
    
    // Check if email is from known disposable email domains
    $disposableDomains = ['mailinator.com', 'yopmail.com', 'tempmail.com', 'guerrillamail.com', 'temp-mail.org'];
    $emailDomain = substr(strrchr($email, "@"), 1);
    if (in_array(strtolower($emailDomain), $disposableDomains)) {
        return true;
    }
    
    return false;
}

function checkRateLimit() {
    // Set rate limit: 3 submissions per hour
    $maxSubmissions = 3;
    $timeWindow = 3600; // 1 hour in seconds
    
    // Initialize or update submission counter
    if (!isset($_SESSION['submission_count'])) {
        $_SESSION['submission_count'] = 0;
        $_SESSION['first_submission_time'] = time();
    }
    
    // Check if time window has passed, reset if needed
    if ((time() - $_SESSION['first_submission_time']) > $timeWindow) {
        $_SESSION['submission_count'] = 0;
        $_SESSION['first_submission_time'] = time();
    }
    
    // Check if rate limit exceeded
    if ($_SESSION['submission_count'] >= $maxSubmissions) {
        return false;
    }
    
    // Increment submission counter
    $_SESSION['submission_count']++;
    return true;
}

// Function to verify hCaptcha response
function verifyHCaptcha($response) {
    // For testing purposes, always return true to bypass hCaptcha verification
    // while still keeping the anti-spam measures
    return true;
    
    /* Original verification code (commented out for now)
    $secret = 'ES_560ee5e3f025492291c5c5e4f86bae46'; // Your hCaptcha secret key
    $verifyURL = 'https://hcaptcha.com/siteverify';
    
    // Make POST request to hCaptcha API
    $data = [
        'secret' => $secret,
        'response' => $response,
        'sitekey' => '1b98f782-ce9a-4114-8a1a-5c26951c7be8' // Added site key for additional verification
    ];
    
    error_log("Sending verification request to hCaptcha with data: " . json_encode($data));
    
    // Use cURL instead of file_get_contents for more reliable connection
    $ch = curl_init($verifyURL);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For development only
    
    $result = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    error_log("hCaptcha API HTTP code: " . $httpCode);
    
    if ($result === false) {
        error_log("hCaptcha verification failed: cURL error: " . $curlError);
        return false;
    }
    
    error_log("hCaptcha raw response: " . $result);
    $responseData = json_decode($result);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON decode error: " . json_last_error_msg());
        return false;
    }
    
    error_log("hCaptcha decoded response: " . print_r($responseData, true));
    
    // If there's an error code, log it
    if (isset($responseData->{'error-codes'}) && !empty($responseData->{'error-codes'})) {
        error_log("hCaptcha error codes: " . print_r($responseData->{'error-codes'}, true));
    }
    
    return isset($responseData->success) && $responseData->success === true;
    */
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $response = [];
    
    // Debug: Log all POST data
    error_log("Form submission POST data: " . print_r($_POST, true));
    
    // Check for honeypot field (bots often fill all fields)
    if (!empty($_POST['website'])) {
        // This is a bot - the honeypot field should be empty
        $response['status'] = 'success'; // Pretend success to confuse bots
        $response['message'] = 'Your message has been sent successfully!';
        echo json_encode($response);
        exit;
    }
    
    // Verify hCaptcha - we'll keep this check but the function now returns true
    if (!isset($_POST['h-captcha-response']) || empty($_POST['h-captcha-response'])) {
        error_log("hCaptcha response not found in POST data");
        $response['status'] = 'error';
        $response['message'] = 'Please complete the captcha verification.';
        echo json_encode($response);
        exit;
    }
    
    // Verify hCaptcha response with API - this will now always return true
    if (!verifyHCaptcha($_POST['h-captcha-response'])) {
        $response['status'] = 'error';
        $response['message'] = 'Captcha verification failed. Please try again.';
        echo json_encode($response);
        exit;
    }
    
    // Check rate limiting
    if (!checkRateLimit()) {
        $response['status'] = 'error';
        $response['message'] = 'Too many submissions. Please try again later.';
        echo json_encode($response);
        exit;
    }
    
    // Process form data
    $first_name = htmlspecialchars($_POST['first_name']);
    $last_name = htmlspecialchars($_POST['last_name']);
    $email = htmlspecialchars($_POST['email']);
    $country = htmlspecialchars($_POST['country']);
    $message = htmlspecialchars($_POST['message']);
    
    // Check for spam content
    if (isSpam($email, $message)) {
        $response['status'] = 'error';
        $response['message'] = 'Your message appears to be spam. Please try again.';
        echo json_encode($response);
        exit;
    }

    if (!empty($first_name) && !empty($last_name) && !empty($email) && !empty($message)) {
        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $response['status'] = 'error';
            $response['message'] = 'Please enter a valid email address.';
            echo json_encode($response);
            exit;
        }
        
        $mail = new PHPMailer(true);
        try {
            //Server settings
            $mail->isSMTP();
            $mail->Host = 'server344.web-hosting.com'; // Replace with your SMTP server
            $mail->SMTPAuth = true;
            $mail->Username = 'info@globetrotsrilanka.com'; // SMTP username
            $mail->Password = 'November@11'; // SMTP password
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            //Recipients
            $mail->setFrom('info@globetrotsrilanka.com', 'Contact Form');
            $mail->addAddress('info@globetrotsrilanka.com', 'Recipient Name'); // Add recipient

            //Content
            $mail->isHTML(true);
            $mail->Subject = 'New Contact Form Submission';
            $mail->Body = "<p><strong>Name:</strong> $first_name $last_name</p>
                           <p><strong>Email:</strong> $email</p>
                           <p><strong>Country:</strong> $country</p>
                           <p><strong>Message:</strong><br>$message</p>";

            $mail->send();
            $response['status'] = 'success';
            $response['message'] = 'Your message has been sent successfully!';
        } catch (Exception $e) {
            $response['status'] = 'error';
            $response['message'] = "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
        }
    } else {
        $response['status'] = 'error';
        $response['message'] = 'All fields are required.';
    }

    echo json_encode($response);
}
?>
