<?php
/*
	Created: August 21, 2018
	Modifed: August 21, 2018
*/

// Libraries loaded via composer
require __DIR__ . '/vendor/autoload.php';

use League\Csv\Reader;
use League\Csv\Writer;

$climate = new League\CLImate\CLImate;

// Location of website list file
$csv_file = __DIR__ . '/websites.csv';

// Location of results file
$csv_results_file = __DIR__ . '/results_first_run.csv';

// Status code used for blocks
$blocked_status_codes = array(403, 412, 451);

// Array of check results
$results = array();

// Results Table
$create_results_table = true;

// Command line options
$long_options = array(
	"disable_table", // Disable output table creation
	"websites:", // Use a custom website.csv file
	"results:" // Use a custom results.csv file
);

$short_options = ''; 

// Set and get options
$cli_options = getopt($short_options, $long_options);

try {
	
	if (php_sapi_name() != 'cli') {
		throw new Exception("gdpr451_first_run.php must be run via the command line.");
	}

	// Check for custom website.csv file
	if (isset($cli_options['websites']) && $cli_options['websites'] != false) {
		$climate->out("website csv file: " . $cli_options['websites']);
		$csv_file = $cli_options['websites'];
	}
	
	// Check for custom results.csv file
	if (isset($cli_options['results']) && $cli_options['results'] != false) {
		$climate->out("results csv file: " . $cli_options['results']);
		$csv_results_file = $cli_options['results'];
	}
	
	// Check for disable_table option
	if (isset($cli_options['disable_table'])) {
		$climate->out("Disabled output table creation");
		$create_results_table = false;
	}
	
	// Load CSV file
	$reader = Reader::createFromPath($csv_file, 'r');
	
	// Set header offset
	$reader->setHeaderOffset(0);

	// Get Records
	$records = $reader->getRecords();
	
	$climate->out("Checking... " . count($reader) . " websites");

	// Loop through each url.
	foreach ($records as $index => $row) {
		$result = array();
		
		$url = trim($row['website']);

		if (empty($url)) {
			$climate->out("No website given in csv row " . $index);
			continue;	
		}
		
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HEADER, 1);
		curl_setopt($ch, CURLINFO_HEADER_OUT, 1);
		
		// Follow redirects
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		
		// Set encoding
		curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
		
		// Set user agent
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; WOW64; rv:62.0) Gecko/20100101 Firefox/62.0');

		// Set Headers
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
		]);
		
		// Number of request tries run
		$i = 0;
	
		// Number seconds to wait (sleep) between each failed request
		$sleep = [5, 10, 30, 60];
		
		// Max number of tries per request
		$max_tries = count($sleep); 
		
		while($i++ < $max_tries) { // Loop
			$error = false; // Default
			$note = "";

			echo "Fetching " . $url . " \r";
			
			// Perform the request
			$raw_response = curl_exec($ch);
			
			// Curl request info
			$response = curl_getinfo($ch);
			$response_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
			
			// HTTP Response Body
			$response['body'] = substr($raw_response, $response['header_size']);
			
			$header_text = substr($raw_response, 0, $response['header_size']);
		 
			if(preg_match_all('/([a-zA-Z-0-9]+): ?(.*)\b/', trim($header_text), $matches, PREG_SET_ORDER, 0)) {

				foreach ($matches as $header) {
			
					$response['headers'][$header[1]] = trim($header[2]);
				
				}
			
			}
	
			if (curl_errno($ch)) { // Failed, cURL error
			
				// Get error details
				$error = true;
				$error_code = curl_errno($ch);
				$error_message = curl_error($ch);
	
				$result = array (
					'url' => $url,
					'returned_url' => $response_url,
					'code' => "N/A",
					'status' => "Failed",
					'note' => $error_message,
				);					
				
				$climate->out($url . " | Failed | " . $error_message . " (" . $error_code . ")");

			} else if ($response['http_code'] === 429) { // Failed, too many requests
			
				$error = true;
				$note = "Too many requests";
				
				$result = array(
					'url' => $url,
					'returned_url' => $response_url,
					'code' => $response['http_code'],
					'status' => "Failed",
					'note' => $note,
				);
				
				$climate->out($index . " " . $response['http_code'] . " " . $url . " | Failed | " . $response['total_time'] . " | " . $note);
			
			} else { // Successful request, check headers
			
				if (in_array($response['http_code'], $blocked_status_codes)) {
				
					$status = "Blocked";
					$note = "Website returned status coode that has been used when blocking EU users.";		
				
				} else if ($response['http_code'] === 302) {
				
					$status = "Blocked";
					$note = "Website redirect to another page using status code 302. Redirect page may be a block page, you may want to check manually.";
				
				} else if (isset($response['headers']['Location'])) {
				
					$status = "Blocked";
					$note = "Website redirected to another page. Redirect page may be a block page, you may want to check manually.";			
					
				} else if ($response['http_code'] === 200) {
				
					$status = "Unblocked";
					$note = "Some websites still use this status code when blocking, you may want check manually.";	
				
				} else {
				
					$status = "Unblocked";
					$note = "";			
				
				}
	
				$results[] = array(
					'url' => $url,
					'returned_url' => $response_url,
					'code' => $response['http_code'],
					'status' => $status,
					'note' => $note,
				);

				$climate->out($index . " " . $response['http_code'] . " " . $url . " | " . $status . " | " . $response['total_time'] . " | " . $note);

				// Break from loop retry loop
				break;
				
			}
			
			// if error, wait and try again
			if ($i < $max_tries && $error === true) {
	
				echo "  Trying again in " . $sleep[$i] . " seconds \n";	
				sleep($sleep[$i]);

			} else { // All requests for URL failed
		
				// Add failed request results to results array
				$results[] = $result;
				
				// Break from while retry loop
				break;
	
			}
		}

	}
	
	if ($create_results_table === true) {
	
		if (count($results) > 0) {
		
			// Output $results as a table.
			$climate->table($results);
		
		}
	
	}
	
	try {
    	
		$writer = Writer::createFromPath($csv_results_file, 'w+');

		// Insert header
		$writer->insertOne(['url', 'returned_url', 'code', 'status', 'note']);
	
		// Insert rows
		$writer->insertAll($results);
	
		$climate->out("Created results file: " . $csv_results_file);
		
	} catch (CannotInsertRecord $e) {
    	
		$climate->out("Failed insert record: " . $e->getRecords());
		$climate->out("Results file not created");
	
	}
	
} catch (Exception $e) {
	$climate->out($e->getMessage());
}