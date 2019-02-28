<?php

require 'vendor/autoload.php';

$DRONE_URL = 'https://drone.nextcloud.com';
$DRONE_TOKEN = 'abc';
$MINIMUM_JOB_ID = 16573;

$help = "Call this script without an argument to fetch all the failed logs of master branch jobs until \$MINIMUM_JOB_ID.

Supply a job number to fetch the failure logs for this specifc job.
";

if ($argc === 2 && ($argv[1] === '-h' || $argv[1] === '--help')) {
	echo $help;
	exit;
}

$client = new GuzzleHttp\Client([
	'headers' => [
		'Authentication' => 'Bearer ' . $DRONE_TOKEN,
	],
	'base_uri' => $DRONE_URL,
]);

if ($argc > 1) {
	$number = (int)$argv[1];
	if (!is_int($number)) {
		echo "Error: argument needs to be a number\n";
	}
	printStats($client, $number, true);
	exit;
}

$res = $client->request('GET', '/api/repos/nextcloud/server/builds');

if ($res->getStatusCode() !== 200) {
	throw new \Exception('Non-200 status code');
}

$data = json_decode($res->getBody(), true);
$lowestJobId = INF;

echo "Checking all the latest CI jobs until $MINIMUM_JOB_ID that ran against master…\n";
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
			echo "Checking {$job['number']} …\n";
			echo "{$job['number']} success\n";
		}
	}
}

for ($i = $lowestJobId - 1; $i > $MINIMUM_JOB_ID; $i--) {
	printStats($client, $i);
}

function printStats($client, $jobId, $force = false) {
	echo "Checking $jobId …\n";

	$res = $client->request('GET', '/api/repos/nextcloud/server/builds/' . $jobId);

	if ($res->getStatusCode() !== 200) {
		throw new \Exception('Non-200 status code');
	}

	$data = json_decode($res->getBody(), true);

	if (!$force && ($data['event'] !== 'push' || $data['branch'] !== 'master')) {
		return;
	}
	if ($data['status'] === 'success') {
		echo "{$data['number']} success\n";
		return;
	}
	if ($data['status'] === 'running') {
		echo "{$data['number']} is still running\n";
		return;
	}
	if ($data['status'] === 'pending') {
		echo "{$data['number']} is pending\n";
		return;
	}

	global $DRONE_URL;
	echo '### Status of [' . $jobId . '](' . $DRONE_URL . '/nextcloud/server/' . $jobId . '): ' . $data['status'] . PHP_EOL . PHP_EOL;

	foreach ($data['procs'] as $proc) {
		if (in_array($proc['state'], ['success', 'pending', 'running'])) {
			continue;
		}

		echo " * " . getProcName($proc['environ']) . PHP_EOL;
		if ($proc['state'] === 'failure' && isset($proc['error']) &&  $proc['error'] === 'Cancelled') {
			echo "   * cancelled\n";
			continue;
		}
		foreach ($proc['children'] as $child) {
			if (in_array($child['state'], ['success', 'skipped', 'cancelled', 'killed'])) {
				continue;
			}
			if ($child['name'] === 'git' && $child['state'] === 'failure') {
				echo "   * git clone failure\n";
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
					echo "     * " . join("\n     * ", $failures) . PHP_EOL;

					echo "<details><summary>Show full log</summary>\n\n```\n";
					foreach ($failures as $failure) {
						$start = strpos($fullLog, $failure);
						$end = strpos($fullLog, "\n\n", $start);
						$realStart = strrpos(substr($fullLog, 0, $start), "\n\n") + 2;

						echo substr($fullLog, $realStart, $end - $realStart) . PHP_EOL;

					}
					echo "```\n</details>\n\n\n";

				} else {
					list(, $b) = explode("--- Failed scenarios:", $fullLog);

					echo $b;
					throw new \Exception("Regex didn't match");
				}
			} else if ($child['name'] === 'git') {
				echo "\t\t\tIgnoring git failure\n";
			} else if ($child['name'] === 'phan') {
				$start = strrpos($fullLog, "\n+") + 2;

				echo "<details><summary>Show full log</summary>\n\n```\n";
				echo "$" . substr($fullLog, $start);
				echo "```\n</details>\n\n\n";
			} else if (in_array($child['name'], [
				'nodb-php7.0',
				'nodb-php7.1',
				'nodb-php7.2',
				'nodb-php7.3',
				'sqlite-php7.0',
				'sqlite-php7.1',
				'sqlite-php7.2',
				'sqlite-php7.3',
				'mysql-php7.0',
				'mysql-php7.1',
				'mysql-php7.2',
				'mysql-php7.3',
				'mysql5.6-php7.0',
				'mysql5.5-php7.0',
				'postgres-php7.0',
				'mysql5.6-php7.1',
				'mysql5.5-php7.1',
				'postgres-php7.1',
				'mysqlmb4-php7.0',
				'mysqlmb4-php7.1',
				'mysqlmb4-php7.2',
				'mysqlmb4-php7.3',
				'nodb-codecov',
				'db-codecov',
			])) {
				$start = strpos($fullLog, "\nThere w") + 1;
				$end = strpos($fullLog, "skipped tests:\n");
				$realEnd = strrpos(substr($fullLog, 0, $end), "--") - 1;

				echo "<details><summary>Show full log</summary>\n\n```\n";
				echo substr($fullLog, $start, $realEnd - $start) . PHP_EOL;
				echo "```\n</details>\n\n\n";
			} else {
				echo "Missing extraction for {$child['name']}\n";
				throw new \Exception("Missing extraction");
			}
		}
	}
}

function getProcName($env) {
	return join(
		', ',
		array_map(function($index, $value) {
			return "$index=$value";
		}, array_keys($env), $env)
	);
}