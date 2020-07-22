<?php

require 'vendor/autoload.php';

$DRONE_URL = 'https://drone.nextcloud.com';
$DRONE_TOKEN = 'abc';
$MINIMUM_JOB_ID = 30751;
$MINIMUM_JOB_ID = 30500;
$SENTRY_DSN = '';

$sentryClient = null;
if ($SENTRY_DSN !== '') {
	$sentryClient = new Raven_Client($SENTRY_DSN);
	$error_handler = new Raven_ErrorHandler($sentryClient);
	$error_handler->registerExceptionHandler();
	$error_handler->registerErrorHandler();
	$error_handler->registerShutdownFunction();
}

$branch = 'stable19';
$help = "Call this script without an argument to fetch all the failed logs of $branch branch jobs until \$MINIMUM_JOB_ID.

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
	printStats($client, $number, $sentryClient, '', true);
	exit;
}

$res = $client->request('GET', '/api/repos/nextcloud/server/builds');

if ($res->getStatusCode() !== 200) {
	throw new \Exception('Non-200 status code');
}

$data = json_decode($res->getBody(), true);
$lowestJobId = INF;

echo "Checking all the latest CI jobs until $MINIMUM_JOB_ID that ran against {$branch}…\n";
foreach ($data as $job) {
	if ($job['number'] < $lowestJobId) {
		$lowestJobId = $job['number'];
	}
	if ($job['event'] === 'push' && $job['source'] === $branch) {
		if ($job['status'] !== 'success') {
			if ($job['number'] < $MINIMUM_JOB_ID) {
				continue;
			}
			printStats($client, $job['number'], $sentryClient, $branch);
		} else {
			echo "Checking {$job['number']} …\n";
			echo "{$job['number']} success\n";
		}
	}
}

for ($i = $lowestJobId - 1; $i > $MINIMUM_JOB_ID; $i--) {
	printStats($client, $i, $sentryClient, $branch);
}

function printStats($client, $jobId, $sentryClient, $branch, $force = false) {
	#echo "Checking $jobId …\n";

	$res = $client->request('GET', '/api/repos/nextcloud/server/builds/' . $jobId);

	if ($res->getStatusCode() !== 200) {
		throw new \Exception('Non-200 status code');
	}

	$data = json_decode($res->getBody(), true);

	if (!$force && ($data['event'] !== 'push' || $data['source'] !== $branch)) {
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

	foreach ($data['stages'] as $stage) {
		if (in_array($stage['status'], ['success', 'pending', 'running'])) {
			continue;
		}

		echo "#### " . $stage['name'] . PHP_EOL;
		if (in_array($stage['status'], ['failure', 'error']) && isset($stage['error']) && $stage['error'] === 'Cancelled') {
			echo " * cancelled - typically means that the tests took longer than the drone CI allows them to run\n";
			continue;
		}
		foreach ($stage['steps'] as $step) {
			if (in_array($step['status'], ['success', 'skipped', 'cancelled', 'killed'])) {
				continue;
			}
			if ($step['name'] === 'git' && $step['status'] === 'failure') {
				echo " * git clone failure - can typically be ignored\n";
				continue;
			}

			try {
				$res = $client->request('GET', "/api/repos/nextcloud/server/builds/$jobId/logs/{$stage['number']}/{$step['number']}");
			} catch (\GuzzleHttp\Exception\ClientException $e) {
				echo "Could not fetch logs\n";
				continue;
			}

			if ($res->getStatusCode() !== 200) {
				throw new \Exception('Non-200 status code for logs');
			}

			$logs = json_decode($res->getBody(), true);

			$fullLog = '';

			foreach ($logs as $log) {
				$fullLog .= $log['out'];
			}

			if ($stage['status'] === 'error' && $stage['error'] === 'Cancelled' && ($stage['stopped'] - $stage['started'] > 1800)) {
				echo "Timeout was reached\n";
				continue;
			}

			if (substr($step['name'], 0, strlen('acceptance')) === 'acceptance' ||
				substr($step['name'], 0, strlen('integration-')) === 'integration-' ) {
				preg_match('!--- Failed scenarios:\n\n(((.+)\n)+)\n!', $fullLog, $matches);

				if (isset($matches[1])) {
					$failures = $matches[1];
					$failures = str_replace([' ', '/drone/src/'], ['', ''], $failures);
					$failures = explode("\n", trim($failures));
					echo " * " . join("\n * ", $failures) . PHP_EOL;

					echo "<details><summary>Show full log</summary>\n\n```\n";
					foreach ($failures as $failure) {
						$start = strpos($fullLog, $failure);
						$end = strpos($fullLog, "\n\n", $start);
						$realStart = strrpos(substr($fullLog, 0, $start), "\n\n") + 2;

						echo substr($fullLog, $realStart, $end - $realStart) . PHP_EOL . PHP_EOL;

					}
					echo "```\n</details>\n\n\n";

				} else {
					echo " * failure block could not be found - most likely this run got canceled\n";
					echo "<details><summary>Show full log</summary>\n\n```\n";
					echo $fullLog;
					echo "```\n</details>\n\n\n";
				}
			} else if ($step['name'] === 'git') {
				echo "\t\t\tIgnoring git failure\n";
			} else if ($step['name'] === 'phan') {
				$start = strrpos($fullLog, "\n+") + 2;

				echo "<details><summary>Show full log</summary>\n\n```\n";
				echo "$" . substr($fullLog, $start);
				echo "```\n</details>\n\n\n";
			} else if (in_array($step['name'], [
				'nodb-php7.2',
				'nodb-php7.3',
				'nodb-php7.4',
				'sqlite-php7.2',
				'sqlite-php7.3',
				'sqlite-php7.4',
				'mariadb10.1-php7.2',
				'mariadb10.2-php7.2',
				'mariadb10.3-php7.2',
				'mariadb10.4-php7.3',
				'mysql8.0-php7.2',
				'mysql5.7-php7.2',
				'mysql5.7-php7.3',
				'mysql5.6-php7.2',
				'mysql-php7.2',
				'mysql-php7.3',
				'postgres9-php7.3',
				'postgres10-php7.2',
				'postgres11-php7.2',
				'postgres-php7.2',
				'postgres-php7.3',
				'mysqlmb4-php7.2',
				'mysqlmb4-php7.3',
				'mysqlmb4-php7.4',
				'nodb-codecov',
				'db-codecov',
			])) {
				$start = strpos($fullLog, "\nThere w") + 1;
				$end = strpos($fullLog, "skipped tests:\n");
				$realEnd = strrpos(substr($fullLog, 0, $end), "--") - 1;

				echo "<details><summary>Show full log</summary>\n\n```\n";
				echo substr($fullLog, $start, $realEnd - $start) . PHP_EOL;
				echo "```\n</details>\n\n\n";
			} else if (in_array($step['name'], [
				'sqlite-php7.3-samba-native',
				'sqlite-php7.3-samba-non-native',
				'sqlite-php7.3-webdav-apache',
				'memcache-memcached',
			])) {
				$start = strpos($fullLog, "\nThere w") + 1;
				$end = strpos($fullLog, "FAILURES!\n") - 1;

				echo "<details><summary>Show full log</summary>\n\n```\n";
				echo substr($fullLog, $start, $end - $start) . PHP_EOL;
				echo "```\n</details>\n\n\n";
			} else if ($step['name'] === 'jsunit') {
				#$start = strpos($fullLog, "\nThere w") + 1;
				#$end = strpos($fullLog, "FAILURES!\n") - 1;
				preg_match_all('!^PhantomJS.*\n?(\t.*\n)*!m', $fullLog, $matches);

				$result = array_filter($matches[0], function($i) {
					return $i !== "PhantomJS not found on PATH\n";
				});

				echo "<details><summary>Show full log</summary>\n\n```\n";
				echo join("", $result) . PHP_EOL;
				echo "```\n</details>\n\n\n";
			} else if ($step['name'] === 'checkers') {

				$log = '';

				if (strpos($fullLog, 'The autoloaders are not up to date')) {

					$log .= "The autoloaders are not up to date\nPlease run: bash build/autoloaderchecker.sh\nAnd commit the result" . PHP_EOL . PHP_EOL;
				}
				if (strpos($fullLog, 'CA bundle is not up to date.')) {

					$log .= "CA bundle is not up to date.\nPlease run: bash build/ca-bundle-checker.sh\nAnd commit the result" . PHP_EOL . PHP_EOL;
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
					echo " * I'm a little sad 🤖" . " and was not able to find the logs for this failed job - please improve me at https://github.com/MorrisJobke/drone-logs to provide this to you\n";
					if ($sentryClient) {
						$sentryClient->captureException(new \Exception('Missing extraction for ' . $step['name']), null, null, [
							'procName' => $stage['name'],
							'proc' => $stage,
							'child' => $step,
							'url' => "/nextcloud/server/$jobId/{$stage['number']}/{$step['number']}",
						]);
					}
				} else {
					echo "<details><summary>Show full log</summary>\n\n```\n";
					echo $log;
					echo "```\n</details>\n\n\n";
				}


			} else {
				echo " * I'm a little sad 🤖" . " and was not able to find the logs for this failed job - please improve me at https://github.com/MorrisJobke/drone-logs to provide this to you\n";
				if ($sentryClient) {
					$sentryClient->captureException(new \Exception('Missing extraction for ' . $step['name']), null, null, [
						'procName' => $stage['environ'],
						'proc' => $stage,
						'child' => $step,
						'url' => "/nextcloud/server/$jobId/{$stage['number']}/{$step['number']}",
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
