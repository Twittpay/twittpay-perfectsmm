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
 * Paste it into your panel's own app/controller/payment.php, alongside the other
 * "if ($method_name == '...')" blocks that are already there.
 *
 * This file is the address you set as the API callback in the admin panel:
 *     https://yourpanel.com/payment/twittpay
 * The gateway calls it server to server. No customer ever sees it, so it answers
 * in plain text and never redirects a browser.
 *
 * WHAT WAS FIXED IN 1.1.0
 * -----------------------
 * 1. THE API KEY WAS SENT UNDER THE WRONG HEADER NAME.
 *    It was "twittpay-API-KEY". The gateway reads "API-KEY". So verification
 *    was always refused and no payment was ever credited by this file.
 *
 * 2. THE ELSE BRANCH COULD NOT RUN AT ALL. It was:
 *       UPDATE payments SET payment_status=:s WHERE client_id=:a, payment_method=:b, ...
 *    Commas instead of AND. That is a MySQL syntax error, so a failed payment was
 *    never marked failed. It also read $data['metadata']['user_id'] - metadata is
 *    a JSON string at that point, so that is a character out of the middle of the
 *    string, not a user id - and it filtered on payment_method 70 while the
 *    method is 71.
 *
 * 3. PENDING WAS TREATED AS A FAILURE. With pending payments switched on, the
 *    gateway calls this file twice: once as PENDING when the customer submits,
 *    then again as COMPLETED once the merchant approves. The old code took the
 *    first call as a failure and tried to set the row to failed - and the second
 *    call then found nothing left to credit. PENDING now leaves the row exactly
 *    as it is and says so.
 *
 * 4. header('Location: ...') was called after echo, which cannot work - the
 *    headers are already sent by then. Removed; this endpoint has no browser.
 *
 * 5. The transaction id is read safely (query string, form post, or JSON body)
 *    instead of assuming $_REQUEST['transactionId'] exists.
 *
 * 6. Metadata is json_decode()d once, in one place, and the user id and order
 *    reference are both taken from there.
 */



//twittpay start
if ($method_name == 'twittpay') {

	// ----------------------------------------------------------------- input
	$transaction_id = '';

	foreach (array('transactionId', 'transaction_id') as $key) {
		if (!empty($_REQUEST[$key])) {
			$transaction_id = trim((string) $_REQUEST[$key]);
			break;
		}
	}

	if ($transaction_id === '') {
		$up_response = file_get_contents('php://input');
		$up_response_decode = json_decode($up_response, true);

		if (is_array($up_response_decode)) {
			foreach (array('transaction_id', 'transactionId') as $key) {
				if (!empty($up_response_decode[$key])) {
					$transaction_id = trim((string) $up_response_decode[$key]);
					break;
				}
			}
		}
	}

	if ($transaction_id === '') {
		http_response_code(400);
		die('Direct access is not allowed.');
	}

	// ---------------------------------------------------------------- verify
	$apiKey = trim($extras['api_key']);
	$apiBaseUrl = rtrim($extras['api_url'], '/');
	$scheme = parse_url($apiBaseUrl, PHP_URL_SCHEME) ?: 'https';
	$host = parse_url($apiBaseUrl, PHP_URL_HOST);
	$apiUrl = $scheme . "://" . $host . "/api/payment/verify";

	$post_body = array(
		'transaction_id' => $transaction_id
	);

	$curl = curl_init();
	curl_setopt_array($curl, [
		CURLOPT_URL => $apiUrl,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_ENCODING => "",
		CURLOPT_MAXREDIRS => 10,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST => "POST",
		CURLOPT_POSTFIELDS => json_encode($post_body),
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
		http_response_code(502);
		die('cURL Error #:' . $err);
	}

	if (empty($response)) {
		http_response_code(502);
		die('Empty response from the payment API.');
	}

	$data = json_decode($response, true);

	if (!is_array($data) || !isset($data['status'])) {
		http_response_code(502);
		die('Unreadable response from the payment API.');
	}

	// The verify endpoint hands metadata back as a JSON STRING, not as an object.
	// Decoding it here is the only place it needs to happen.
	$info = array();

	if (isset($data['metadata'])) {
		if (is_string($data['metadata']) && $data['metadata'] !== '') {
			$decoded_meta = json_decode($data['metadata'], true);
			if (is_array($decoded_meta)) {
				$info = $decoded_meta;
			}
		} elseif (is_array($data['metadata'])) {
			$info = $data['metadata'];
		}
	}

	if (empty($info['txnid']) || empty($info['user_id'])) {
		http_response_code(400);
		die('This payment carries no order reference.');
	}

	$order_id = $info['txnid'];
	$client_id = $info['user_id'];
	$status = strtoupper((string) $data['status']);

	// ------------------------------------------------------------ safe guard
	// Credited already? Then this is the second copy of the same call and there
	// is nothing to do. The gateway is allowed to call more than once.
	if (countRow(['table' => 'payments', 'where' => ['client_id' => $client_id, 'payment_method' => 71, 'payment_status' => 3, 'payment_delivery' => 2, 'payment_extra' => $order_id]])) {
		die('Already processed.');
	}

	// Still waiting to be credited? If not, this payment was cancelled or does
	// not belong to us, and we leave it alone.
	if (!countRow(['table' => 'payments', 'where' => ['client_id' => $client_id, 'payment_method' => 71, 'payment_status' => 1, 'payment_delivery' => 1, 'payment_extra' => $order_id]])) {
		die('Nothing waiting for this order reference.');
	}

	// --------------------------------------------------------------- pending
	if ($status == 'PENDING') {
		// Do not touch the row. The gateway will call again with COMPLETED once
		// the merchant approves it, and that call has to find it still waiting.
		die('PENDING - waiting for the merchant to approve.');
	}

	// ------------------------------------------------------------- completed
	if ($status == 'COMPLETED') {

		$payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra ');
		$payment->execute(['extra' => $order_id]);
		$payment = $payment->fetch(PDO::FETCH_ASSOC);

		if (!$payment) {
			http_response_code(404);
			die('Order reference not found.');
		}

		$payment_bonus = $conn->prepare('SELECT * FROM payments_bonus WHERE bonus_method=:method && bonus_from<=:from ORDER BY bonus_from DESC LIMIT 1');
		$payment_bonus->execute(['method' => $method['id'], 'from' => $payment['payment_amount']]);
		$payment_bonus = $payment_bonus->fetch(PDO::FETCH_ASSOC);

		if ($payment_bonus) {
			$amount = $payment['payment_amount'] + (($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100);
			$bonus_amount = ($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100;
		} else {
			$amount = $payment['payment_amount'];
		}

		$conn->beginTransaction();

		$update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_status=:status, payment_delivery=:delivery WHERE payment_id=:id ');
		$update = $update->execute(['balance' => $payment['balance'], 'status' => 3, 'delivery' => 2, 'id' => $payment['payment_id']]);

		$balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id ');
		$balance = $balance->execute(['id' => $payment['client_id'], 'balance' => $payment['balance'] + $amount]);

		$insert = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ');
		$insert25 = $conn->prepare("INSERT INTO payments SET client_id=:client_id , client_balance=:client_balance , payment_amount=:payment_amount , payment_method=:payment_method ,
                     payment_status=:status, payment_delivery=:delivery , payment_note=:payment_note , payment_create_date=:payment_create_date , payment_extra=:payment_extra , bonus=:bonus");

		if ($payment_bonus) {
			$insert25->execute(array(
				"client_id" => $payment['client_id'],
				"client_balance" => (($payment['balance'] + $amount) - $bonus_amount),
				"payment_amount" => $bonus_amount,
				"payment_method" =>  1,
				'status' => 3,
				'delivery' => 2,
				"payment_note" => "Bonus added",
				"payment_create_date" => date('Y-m-d H:i:s'),
				"payment_extra" => "Bonus added for previous payment",
				"bonus" => 1
			));
			$insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["currency"] . ' payment has been made with ' . $method['method_name'] . ' and included %' . $payment_bonus['bonus_amount'] . ' bonus.', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
		} else {
			$insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["currency"] . ' payment has been made with ' . $method['method_name'], 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
		}

		if ($update && $balance) {
			$conn->commit();
			die('OK');
		}

		$conn->rollBack();
		http_response_code(500);
		die('The balance could not be updated.');
	}

	// ---------------------------------------------------------------- failed
	// Anything that is not PENDING and not COMPLETED is a payment that will not
	// happen - cancelled by the customer, or rejected by the merchant.
	$update = $conn->prepare('UPDATE payments SET payment_status=:payment_status WHERE client_id=:client_id AND payment_method=:payment_method AND payment_delivery=:payment_delivery AND payment_extra=:payment_extra');
	$update->execute(['payment_status' => 2, 'client_id' => $client_id, 'payment_method' => 71, 'payment_delivery' => 1, 'payment_extra' => $order_id]);

	die('FAILED - this payment was cancelled or rejected.');
}

//twittpay end
