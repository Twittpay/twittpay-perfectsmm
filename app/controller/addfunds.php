<?php
/*
 * Title: Smm Panel Module
 * Description:
 * Author: TwittPay
 * Date: 2024-08-14
 *
 * TWITTPAY FOR PERFECT SMM PANEL - version 1.1.0
 *
 * THIS IS A BLOCK TO PASTE, NOT A FILE TO DROP IN.
 * Paste the two $_GET blocks near the top of your panel's own
 * app/controller/addfunds.php, and paste the "elseif ($method_id == 71)" block
 * into the chain of payment methods that is already in that file.
 *
 * WHAT WAS FIXED IN 1.1.0
 * -----------------------
 * 1. THE API KEY WAS SENT UNDER THE WRONG HEADER NAME.
 *    It was "twittpay-API-KEY". The gateway reads "API-KEY". A key sent under
 *    a name nobody reads is the same as sending no key at all, so every request
 *    came back as "Invalid API Request" and no payment page was ever produced.
 *    The same mistake was in payment.php, so verification could not work either.
 *
 * 2. A FAILED RESPONSE WAS TREATED AS A SUCCESS.
 *    The test was isset($result['status']). A failure also carries a status - it
 *    is 0 - so isset() is true for both. The payment row was inserted anyway and
 *    the customer was handed a redirect form pointing at an undefined
 *    $result['payment_url'], which is a blank page. The test now looks for the
 *    payment_url itself.
 *
 * 3. The response is checked for being readable JSON before it is used, and the
 *    payment row is only inserted after the gateway has actually given us a
 *    payment page.
 *
 * 4. $_GET["success"] and $_GET["cancel"] are read with isset(), so the page no
 *    longer raises a PHP warning on every normal visit to Add Funds.
 */

if (isset($_GET["success"]) && $_GET["success"]) :
    $success = 1;
    $successText = "Your payment paid successfully";
endif;

if (isset($_GET["cancel"]) && $_GET["cancel"]) :
    $error = 1;
    $errorText = "Your payment cancelled successfully";
endif;

//body of twittpay start --
elseif ($method_id == 71) :

	$apiKey = $extra['api_key'];
	$apiBaseUrl = rtrim($extra['api_url'], '/');
	$scheme = parse_url($apiBaseUrl, PHP_URL_SCHEME) ?: 'https';
	$host = parse_url($apiBaseUrl, PHP_URL_HOST);
	$apiUrl = $scheme . "://" . $host . "/api/payment/create";

	$final_amount = $amount * $extra['exchange_rate'];
	$txnid = substr(hash('sha256', mt_rand() . microtime()), 0, 20);

	// webhook_url is what makes a payment the merchant approves later still land
	// in the balance. By the time it is approved the customer is long gone from
	// the browser, so success_url on its own would never fire.
	$posted = [
		'cus_name' => isset($user['username']) ? $user['username'] : 'John Doe',
		'cus_email' => $user['email'],
		'amount' => $final_amount,
		'webhook_url' => site_url('payment/twittpay'),
		'success_url' => site_url('addfunds?success=true'),
		'cancel_url' => site_url('addfunds?cancel=true'),
		'metadata' => [
			'user_id' => $user['client_id'],
			'txnid'   => $txnid
		]
	];


	$curl = curl_init();

	curl_setopt_array($curl, [
		CURLOPT_URL => $apiUrl,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_CUSTOMREQUEST => "POST",
		CURLOPT_POSTFIELDS => json_encode($posted),
		CURLOPT_HTTPHEADER => [
			// The gateway reads this exact header name. Any other name counts as
			// no key at all.
			"API-KEY: " . $apiKey,
			"Content-Type: application/json"
		],
	]);

	$response = curl_exec($curl);
	$err = curl_error($curl);
	curl_close($curl);

	if ($err) {
		errorExit("cURL Error #:" . $err);
	}

	$result = json_decode($response, true);

	if (!is_array($result)) {
		errorExit("The payment gateway sent back a response we could not read.");
	}

	// A refused request still carries a status, so the payment_url is the only
	// honest success test.
	if (empty($result['payment_url'])) {
		errorExit(isset($result['message']) ? $result['message'] : "The payment gateway refused this request.");
	}

	$payment_url = $result['payment_url'];
	$order_id = $txnid;

	$insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_create_date=:date,payment_update_date=:date, payment_ip=:ip, payment_extra=:extra");
	$insert = $insert->execute(array("c_id" => $user['client_id'], "amount" => $amount, "code" => $paymentCode, "method" => $method_id, "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $order_id));

	if (!$insert) {
		errorExit("The payment could not be saved. Please try again.");
	}

	// Redirects to twittpay
	echo '<div class="dimmer active" style="min-height: 400px;">
		<div class="loader"></div>
		<div class="dimmer-content">
			<center>
				<h2>Please do not refresh this page</h2>
			</center>
			<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" style="margin:auto;background:#fff;display:block;" width="200px" height="200px" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid">
				<circle cx="50" cy="50" r="32" stroke-width="8" stroke="#e15b64" stroke-dasharray="50.26548245743669 50.26548245743669" fill="none" stroke-linecap="round">
					<animateTransform attributeName="transform" type="rotate" dur="1s" repeatCount="indefinite" keyTimes="0;1" values="0 50 50;360 50 50"></animateTransform>
				</circle>
				<circle cx="50" cy="50" r="23" stroke-width="8" stroke="#f8b26a" stroke-dasharray="36.12831551628262 36.12831551628262" stroke-dashoffset="36.12831551628262" fill="none" stroke-linecap="round">
					<animateTransform attributeName="transform" type="rotate" dur="1s" repeatCount="indefinite" keyTimes="0;1" values="0 50 50;-360 50 50"></animateTransform>
				</circle>
			</svg>
			<form action="' . $payment_url . '" method="get" name="twittpayForm" id="pay">
				<script type="text/javascript">
					document.getElementById("pay").submit();
				</script>
			</form>
		</div>
	</div>';


//end twittpay body
