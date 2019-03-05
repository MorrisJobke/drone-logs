<?php

require 'vendor/autoload.php';

$DRONE_URL = 'https://drone.nextcloud.com';
$DRONE_TOKEN = 'abc';
$MINIMUM_JOB_ID = 16573;
$SENTRY_DSN = '';

$sentryClient = null;
if ($SENTRY_DSN !== '') {
	$sentryClient = new Raven_Client($SENTRY_DSN);
	$error_handler = new Raven_ErrorHandler($sentryClient);
	$error_handler->registerExceptionHandler();
	$error_handler->registerErrorHandler();
	$error_handler->registerShutdownFunction();
}

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
	printStats($client, $number, $sentryClient, true);
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
			printStats($client, $job['number'], $sentryClient);
		} else {
			echo "Checking {$job['number']} …\n";
			echo "{$job['number']} success\n";
		}
	}
}

for ($i = $lowestJobId - 1; $i > $MINIMUM_JOB_ID; $i--) {
	printStats($client, $i, $sentryClient);
}

function printStats($client, $jobId, $sentryClient, $force = false) {
	#echo "Checking $jobId …\n";

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
			echo "   * cancelled - typically means that the tests took longer than the drone CI allows them to run\n";
			continue;
		}
		foreach ($proc['children'] as $child) {
			if (in_array($child['state'], ['success', 'skipped', 'cancelled', 'killed'])) {
				continue;
			}
			if ($child['name'] === 'git' && $child['state'] === 'failure') {
				echo "   * git clone failure - can typically be ignored\n";
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

			if (substr($child['name'], 0, strlen('acceptance')) === 'acceptance' ||
				substr($child['name'], 0, strlen('integration-')) === 'integration-' ) {
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
			} else if (in_array($child['name'], [
				'sqlite-php7.0-samba-native',
				'sqlite-php7.0-samba-non-native',
				'sqlite-php7.1-samba-native',
				'sqlite-php7.1-samba-non-native',
				'memcache-memcached',
			])) {
				$start = strpos($fullLog, "\nThere w") + 1;
				$end = strpos($fullLog, "FAILURES!\n") - 1;

				echo "<details><summary>Show full log</summary>\n\n```\n";
				echo substr($fullLog, $start, $end - $start) . PHP_EOL;
				echo "```\n</details>\n\n\n";
			} else if ($child['name'] === 'checkers') {

				$log = '';

				if (strpos($fullLog, 'The autoloaders are not up to date')) {

					$log .= "The autoloaders are not up to date\nPlease run: bash build/autoloaderchecker.sh\nAnd commit the result" . PHP_EOL . PHP_EOL;
				}
				if (strpos($fullLog, 'App is not compliant')) {
					$end = strpos($fullLog, 'App is not compliant');
					$start = strrpos(substr($fullLog, 0, $end), 'Testing');

					$subLog = substr($fullLog, $start);
					$subLog = str_replace('Nextcloud is not installed - only a limited number of commands are available', 'replace', $subLog);
					$subLog = preg_replace('/Testing \w+\\nApp is compliant - awesome job!/', '', $subLog);

					$log .= $subLog . PHP_EOL . PHP_EOL;
				}

				if ($log === '') {
					echo "   * I'm a little sad 🤖" . " and was not able to find the logs for this failed job - please improve me at https://github.com/MorrisJobke/drone-logs to provide this to you\n";
					if ($sentryClient) {
						$sentryClient->captureException(new \Exception('Missing extraction for ' . $child['name']), null, null, [
							'procName' => getProcName($proc['environ']),
							'proc' => $proc,
							'child' => $child,
							'url' => "/nextcloud/server/$jobId/{$child['pid']}",
						]);
					}
				} else {
					echo "<details><summary>Show full log</summary>\n\n```\n";
					echo $log;
					echo "```\n</details>\n\n\n";
				}


			} else {
				echo "   * I'm a little sad 🤖" . " and was not able to find the logs for this failed job - please improve me at https://github.com/MorrisJobke/drone-logs to provide this to you\n";
				if ($sentryClient) {
					$sentryClient->captureException(new \Exception('Missing extraction for ' . $child['name']), null, null, [
						'procName' => getProcName($proc['environ']),
						'proc' => $proc,
						'child' => $child,
						'url' => "/nextcloud/server/$jobId/{$child['pid']}",
					]);
				}
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