<?php
/*
	Created: July 13, 2018
	Modifed: July 25, 2018
*/

// Libraries loaded via composer
require __DIR__ . '/vendor/autoload.php';

use League\Csv\Reader;
use League\Csv\Writer;

$climate = new League\CLImate\CLImate;

// Location of website list file
$csv_file = __DIR__ . '/websites.csv';

// Location of results file
$csv_results_file = __DIR__ . '/results.csv';

// Array of check results
$results = array();

try {
	
	if (php_sapi_name() != 'cli') {
		throw new Exception("checker.php must be run via the command line.");
	}

	// Load CSV file
	$reader = Reader::createFromPath($csv_file, 'r');
	
	// Set header offset
	$reader->setHeaderOffset(0);

	// Get Records
	$records = $reader->getRecords();
	
	$climate->out("Checking... " . count($reader) . " websites");

	// Loop through each item
	foreach ($records as $index => $row) {
		$note = "";
		
		$url = trim($row['website']);
		$blocked_status = trim($row['blocked_status_code']);
		$blocked_redirect = trim($row['blocked_redirect_url']);
		$user_agent = $row['user_agent'];

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl, CURLOPT_HEADER, 1);
		curl_setopt($curl, CURLINFO_HEADER_OUT, 1);
		curl_setopt($curl, CURLOPT_ENCODING, 'gzip, deflate');
		//curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; WOW64; rv:62.0) Gecko/20100101 Firefox/62.0');
		
		// Set user_agent if $user_agent is not empty
		if (!empty($user_agent)) { 
			curl_setopt($curl, CURLOPT_USERAGENT, $user_agent);
		}
	
		// Perform the request
		$raw_response = curl_exec($curl);

		// Curl info
		$response = curl_getinfo($curl);

		// HTTP Response Body
		$response['body'] = substr($raw_response, $response['header_size']);
		
		$header_text = substr($raw_response, 0, $response['header_size']);
		 
		if(preg_match_all('/([a-zA-Z-0-9]+): ?(.*)\b/', trim($header_text), $matches, PREG_SET_ORDER, 0)) {

			foreach ($matches as $header) {
			
				$response['headers'][$header[1]] = trim($header[2]);
				
			}
			
		}
		
		if (curl_errno($curl)) { // Request frailed, cURL error
			
			$error_code = curl_errno($curl);
			$error_message = curl_error($curl);

			$results[] = array (
				'url' => $url,
				'status' => "Failed",
				'code' => "N/A",
				'note' => $error_message,
			);

			$climate->out($url . " | Failed | " . $error_message . " (" . $error_code . ")");

		} else { // Successful request, check headers
			
			if (!empty($blocked_redirect) && $response['http_code'] == $blocked_status && $response['headers']['Location'] == $blocked_redirect) { // Status codes and redirect urls match.

				$status = "Blocked";
				$note = $response['headers']['Location'];

			} else if ($response['http_code'] == $blocked_status) { // Status code match.
			
				$status = "Blocked";
				
			} else { // No match, website maybe accessible.
			
				$status = "Unblocked";
				
			}
	
			$results[] = array (
				'url' => $url,
				'status' => $status,
				'code' => $response['http_code'],
				'note' => $note,
			);

			$climate->out($index . " " . $url . " | " . $status . " | " . $response['http_code'] . " | " . $response['total_time'] . " | " . $note);

		}

		// Wait for 1 seconds between each request.
		sleep(1);

	}
	
	// Output $results as a table.
	$climate->table($results);
	
	// Save results to disk.
	$writer = Writer::createFromPath($csv_results_file, 'w+');
	
	// Insert header
	$writer->insertOne(['url', 'status', 'code', 'note']);
	
	// Insert rows
	$writer->insertAll($results);
	
} catch (Exception $e) {
	$climate->out($e->getMessage());
}