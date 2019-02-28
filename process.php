<?php

require 'vendor/autoload.php';

$DRONE_URL = 'https://drone.nextcloud.com';
$DRONE_TOKEN = 'abc';
$JOB_ID = '16516';

$client = new GuzzleHttp\Client([
	'headers' => [
		'Authentication' => 'Bearer ' . $DRONE_TOKEN,
	],
	'base_uri' => $DRONE_URL,
]);

$res = $client->request('GET', '/api/repos/nextcloud/server/builds', [
	'query' => [
		#'page' => $i,
	],
]);

if ($res->getStatusCode() !== 200) {
	throw new \Exception('Non-200 status code');
}

$data = json_decode($res->getBody(), true);
$lowestJobId = INF;

foreach ($data as $job) {
	if ($job['number'] < $lowestJobId) {
		$lowestJobId = $job['number'];
	}
	if ($job['event'] === 'push' && $job['branch'] === 'master') {
		if ($job['status'] !== 'success') {
			if ($job['number'] < $MINIMUM_JOB_ID) {
				continue;
			}
			printStats($client, $job['number']);
		} else {
			echo "{$job['number']} success\n";
		}
	}
}

for ($i = $lowestJobId - 1; $i > $MINIMUM_JOB_ID; $i--) {
	printStats($client, $i);
}

function printStats($client, $jobId) {

	$res = $client->request('GET', '/api/repos/nextcloud/server/builds/' . $jobId);

	if ($res->getStatusCode() !== 200) {
		throw new \Exception('Non-200 status code');
	}

	$data = json_decode($res->getBody(), true);

	if ($data['event'] !== 'push' || $data['branch'] !== 'master') {
		return;
	}
	if ($data['status'] === 'success') {
		echo "{$data['number']} success\n";
		return;
	}

	echo 'Status of ' . $jobId . ': ' . $data['status'] . PHP_EOL;

	$counts = [
		'success' => 0,
		'failure' => 0,
		'cancelled' => 0,
	];

	foreach ($data['procs'] as $proc) {
		$counts[$proc['state']]++;

		if ($proc['state'] === 'success') {
			continue;
		}

		if ($proc['state'] === 'pending') {
			continue;
		}

		echo "\t{$proc['state']} " . getProcName($proc['environ']) . PHP_EOL;
		foreach ($proc['children'] as $child) {
			if ($child['state'] === 'success') {
				continue;
			}
			if ($child['state'] === 'skipped') {
				continue;
			}
			if ($child['name'] === 'git' && $child['state'] === 'failure') {
				continue;
			}

			echo "\t\t" . $child['state'] . ' ' . $child['name'] . ' ' . $child['pid'] . PHP_EOL;

			if ($child['state'] === 'cancelled') {
				continue;
			}

			$res = $client->request('GET', "/api/repos/nextcloud/server/logs/$jobId/{$child['pid']}");

			if ($res->getStatusCode() !== 200) {
				throw new \Exception('Non-200 status code for logs');
			}

			$logs = json_decode($res->getBody(), true);

			$fullLog = '';

			foreach ($logs as $log) {
				$fullLog .= $log['out'];
			}

			if (substr($child['name'], 0, strlen('acceptance')) === 'acceptance') {
				preg_match('!--- Failed scenarios:\n\n(((.+)\n)+)\n!', $fullLog, $matches);

				if (isset($matches[1])) {
					$failures = $matches[1];
					$failures = str_replace([' ', '/drone/src/github.com/nextcloud/server/'], ['', ''], $failures);
					$failures = explode("\n", trim($failures));
					echo "\t\t\t" . join("\n\t\t\t", $failures) . PHP_EOL;

					foreach ($failures as $failure) {
						$start = strpos($fullLog, $failure);
						$end = strpos($fullLog, "\n\n", $start);
						$realStart = strrpos(substr($fullLog, 0, $start), "\n\n") + 2;

						echo substr($fullLog, $realStart, $end - $realStart) . PHP_EOL . PHP_EOL;


					}

				} else {
					list($a, $b) = explode("--- Failed scenarios:", $fullLog);

					echo $b;
					throw new \Exception("Regex didn't match");
				}
			} else if ($child['name'] === 'git') {
				echo "\t\t\tIgnoring git failure\n";
			} else {
				echo "Missing extraction for {$child['name']}";
				#throw new \Exception("Missing extraction");
			}
		}
	}

	/*
	echo "success: {$counts['success']}\n";
	echo "failure: {$counts['failure']}\n";
	echo "cancelled: {$counts['cancelled']}\n";
	*/
}

function getProcName($env) {
	return join(
		', ',
		array_map(function($index, $value) {
			return "$index=$value";
		}, array_keys($env), $env)
	);
}