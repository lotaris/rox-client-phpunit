<?php

namespace Lotaris\RoxClientPHPUnit;

use Rhumsaa\Uuid\Uuid;
use Doctrine\Common\Annotations\AnnotationReader;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Exception\ParseException;
use Guzzle\Http\Client;

/**
 * This TestListener sends results to ROX Center at the end of each test suite.
 *
 * @author Francois Vessaz <francois.vessaz@lotaris.com>
 */
class RoxTestListener implements \PHPUnit_Framework_TestListener {

	const ERROR_MESSAGE_MAX_LENGTH = 65535;
	const PAYLOAD_ENCODING = "UTF-8";

	private $roxClientLog;
	private $isVerbose;
	private $config;
	private $httpClient;
	private $annotationReader;
	private $testsPayloadUrl;
	private $testsRunUid;
	private $testSuiteStartTime;
	private $currentTest;
	private $currentTestSuite;
	private $nbOfTests;
	private $nbOfRoxableTests;
	private $cache;

	public function __construct($options = null) {
		try {
			// init log
			if (isset($options['verbose'])) {
				$this->isVerbose = $options['verbose'];
			} else {
				$this->isVerbose = false;
			}
			if ($this->isVerbose) {
				$this->roxClientLog = "INFO ROX client is verbose.\n";
			} else {
				$this->roxClientLog = '';
			}

			// Load ROX user configurations files
			if (isset($options['home'])) {
				$home = $options['home'];
			} else if (isset($_SERVER['HOME'])) {
				$home = $_SERVER['HOME'];
			} else {
				throw new RoxClientException("ERROR: No variables set for user home either with PHP \$_SERVER['HOME'], either with PHPunit TestListener arguments in phpunit.xml.dist");
			}
			$userConfigFile = file_get_contents($home . '/.rox/config.yml');
			$projectConfigFile = file_get_contents('rox.yml');
			if ($userConfigFile === false && $projectConfigFile === false) {
				throw new RoxClientException("ERROR: Unable to load both ROX user config file ($home/.rox/config.yml) and ROX project config file (<rojectRoot>/rox.yml).");
			}

			// parse config files
			$yaml = new Parser();
			try {
				$userConfig = $yaml->parse($userConfigFile);
			} catch (ParseException $e) {
				$this->roxClientLog .= $e->getMessage() . "\n";
			}
			try {
				$projectConfig = $yaml->parse($projectConfigFile);
			} catch (ParseException $e) {
				$this->roxClientLog .= $e->getMessage() . "\n";
			}
			if (!isset($userConfig) && !isset($projectConfig)) {
				throw new RoxClientException("ERROR: Unable to parse both ROX user config file ($home/.rox/config.yml) and ROX project config file (<rojectRoot>/rox.yml).");
			}

			// override/augment user config with project config
			if ($projectConfig){
				$this->config = $this->overrideConfig($userConfig, $projectConfig);
			}

			// override/augment config with ROX environment variables
			if (getenv("ROX_SERVER")) {
				$this->config['server'] = getenv("ROX_SERVER");
				$this->roxClientLog .= "WARNING: use environment variable instead of config files (ROX_SERVER={$this->config['server']}).\n";
			}
			if (getenv("ROX_PUBLISH")) {
				$publish = getenv("ROX_PUBLISH");
				$this->config['payload']['publish'] = ($publish == 1 || strtoupper($publish) == "TRUE" || strtoupper($publish) == "T");
				$this->roxClientLog .= "WARNING: use environment variable instead of config files (ROX_PUBLISH=$publish).\n";
			} else {
				// default is true for this setting
				$this->config['payload']['publish'] = true;
			}
			if (getenv("ROX_PRINT_PAYLOAD")) {
				$print = getenv("ROX_PRINT_PAYLOAD");
				$this->config['payload']['print'] = ($print == 1 || strtoupper($print) == "TRUE" || strtoupper($print) == "T");
				$this->roxClientLog .= "WARNING: use environment variable instead of config files (ROX_PRINT_PAYLOAD=$print).\n";
			}
			if (getenv("ROX_SAVE_PAYLOAD")) {
				$save = getenv("ROX_SAVE_PAYLOAD");
				$this->config['payload']['save'] = ($save == 1 || strtoupper($save) == "TRUE" || strtoupper($save) == "T");
				$this->roxClientLog .= "WARNING: use environment variable instead of config files (ROX_SAVE_PAYLOAD=$save).\n";
			}
			if (getenv("ROX_WORKSPACE")) {
				$this->config['workspace'] = getenv("ROX_WORKSPACE");
				$this->roxClientLog .= "WARNING: use environment variable instead of config files (ROX_WORKSPACE={$this->config['workspace']}).\n";
			}
			if (getenv("ROX_TEST_RUN_UID")) {
				$this->testsRunUid = getenv("ROX_TEST_RUN_UID");
			} else if (file_exists("{$this->config['workspace']}/uid")) {
				$uid = file_get_contents("{$this->config['workspace']}/uid");
				if ($uid) {
					$this->testsRunUid = $uid;
				} else {
					throw new RoxClientException("ERROR: A UID file exist in workspace, but it cannot be read.");
				}
			} else {
				$this->testsRunUid = Uuid::uuid4()->toString();
			}

			// select ROX server
			if (isset($this->config['server'])) {
				$roxServerName = $this->config['server'];
			} else {
				throw new RoxClientException("ERROR: no ROX server defined either by environment variable, either by config files.");
			}

			// get server root URL
			if (isset($this->config['servers'][$roxServerName]['apiUrl'])) {
				$roxServerUrl = $this->config['servers'][$roxServerName]['apiUrl'];
				if (!filter_var($roxServerUrl, FILTER_VALIDATE_URL)) {
					throw new RoxClientException("ERROR: invalid url for $roxServerName ($roxServerUrl)");
				}
			} else {
				throw new RoxClientException("ERROR: no apiUrl found for $roxServerName.");
			}

			// get server credentials
			if (isset($this->config['servers'][$roxServerName]['apiKeyId'])) {
				$roxApiKeyId = $this->config['servers'][$roxServerName]['apiKeyId'];
			} else {
				throw new RoxClientException("ERROR: missing apiKeyId for $roxServerName.");
			}
			if (isset($this->config['servers'][$roxServerName]['apiKeySecret'])) {
				$roxApiKeySecret = $this->config['servers'][$roxServerName]['apiKeySecret'];
			} else {
				throw new RoxClientException("ERROR: missing apiKeySecret for $roxServerName.");
			}

			// get payload v1 links from ROX root URL
			$this->httpClient = new Client();
			$this->httpClient->setDefaultOption('headers/Authorization', "RoxApiKey id=\"$roxApiKeyId\" secret=\"$roxApiKeySecret\"");
			$request = $this->httpClient->get($roxServerUrl);
			$response = $request->send();
			$roxRessources = json_decode($response->getBody(), true);
			if (isset($roxRessources['_links']['v1:test-payloads']['href'])) {
				$this->testsPayloadUrl = $roxRessources['_links']['v1:test-payloads']['href'];
			} else {
				throw new RoxClientException("ERROR: missing link for v1:test-payloads in $roxServerName response.");
			}
			
			// load cache, if needed
			if (isset($this->config['payload']['cache']) && $this->config['payload']['cache']){
				if (!isset($this->config['workspace'])){
					throw new RoxClientException("ERROR: missing workspace in config files or environment variables. Can not locate cache.");
				}
				if (!isset($this->config['project']['apiId'])){
					throw new RoxClientException("ERROR: missing apiId for project in config files.");
				}
				$this->cache = array();
				$cachePath = "{$this->config['workspace']}/phpunit/servers/{$this->config['server']}/cache.json";
				if (file_exists($cachePath)){
					$cacheJson = file_get_contents($cachePath);
					if (!$cacheJson){
						throw new RoxClientException("ERROR: unable to read cache file ($cachePath)");
					}
					$cache = json_decode($cacheJson, true);
					if (!$cache){
						throw new RoxClientException("ERROR: unable to decode JSON of cache file ($cachePath)");
					}
					if (isset($cache[$this->config['project']['apiId']]) && is_array($cache[$this->config['project']['apiId']])){
						$this->cache = $cache[$this->config['project']['apiId']];
					} else {
						$this->roxClientLog .= "WARNING: no existing cache data for this project.\n";
					}
				}
			}
			var_dump($this->cache);

			// init class properties
			$this->testSuiteStartTime = intval(microtime(true) * 1000); // UNIX timestamp in ms
			$this->annotationReader = new AnnotationReader();
		} catch (RoxClientException $e) {
			$this->roxClientLog .= $e->getMessage() . "\n";
		} catch (Exception $e) {
			$this->roxClientLog .= $e->getMessage() . "\n";
		}
	}

	private function overrideConfig(array $baseConfig, array $overridingConfig) {
		$res = $baseConfig;
		foreach ($overridingConfig as $key => $value) {
			if (isset($baseConfig[$key]) && is_array($baseConfig[$key]) && is_array($value) && $this->is_assoc($baseConfig[$key]) && $this->is_assoc($value)) {
				$res[$key] = $this->overrideConfig($baseConfig[$key], $value);
			} else if (isset($baseConfig[$key]) && is_array($baseConfig[$key]) && is_array($value) && !$this->is_assoc($baseConfig[$key]) && !$this->is_assoc($value)) {
				$res[$key] = array_merge($baseConfig[$key], $value);
			} else {
				$res[$key] = $value;
			}
		}
		return $res;
	}

	/**
	 * Check if an array as at least one associative keys.
	 */
	private function is_assoc(array $array) {
		return (bool) count(array_filter(array_keys($array), 'is_string'));
	}

	public function addError(\PHPUnit_Framework_Test $test, \Exception $e, $time) {
		if ($this->currentTest) {
			$this->currentTest['p'] = false;
			$this->currentTest['m'] = $this->jTraceEx($e) . "\n";
		}
	}

	public function addFailure(\PHPUnit_Framework_Test $test, \PHPUnit_Framework_AssertionFailedError $e, $time) {
		if ($this->currentTest) {
			$this->currentTest['p'] = false;
			$this->currentTest['m'] = $this->jTraceEx($e) . "\n";
		}
	}

	public function addIncompleteTest(\PHPUnit_Framework_Test $test, \Exception $e, $time) {
		if ($this->currentTest) {
			$this->currentTest['p'] = false;
			$this->currentTest['m'] = "This test is marked as incomplete.";
		}
	}

	public function addSkippedTest(\PHPUnit_Framework_Test $test, \Exception $e, $time) {
		if ($this->currentTest) {
			$this->currentTest['f'] = RoxableTest::INACTIVE_TEST_FLAG;
		}
	}

	public function endTest(\PHPUnit_Framework_Test $test, $time) {
		if ($this->currentTest) {
			// set duration
			$this->currentTest['d'] = intval($time * 1000);

			// check message length and truncate it if needed
			if (isset($this->currentTest['m']) && strlen(mb_convert_encoding($this->currentTest['m'], self::PAYLOAD_ENCODING)) > self::ERROR_MESSAGE_MAX_LENGTH) {
				$this->currentTest['m'] = mb_substr($this->currentTest['m'], 0, self::ERROR_MESSAGE_MAX_LENGTH, self::PAYLOAD_ENCODING);
				$this->roxClientLog .= "WARNING some error messages were truncated.\n";
			}

			// add test results to test suite
			array_push($this->currentTestSuite, $this->currentTest);
		} else if ($this->isVerbose) {
			$this->roxClientLog .= "WARNING test {$test->getName()} is not roxable.\n";
		}
	}

	public function endTestSuite(\PHPUnit_Framework_TestSuite $suite) {
		if (RoxClientException::exceptionOccured()) {
			$this->roxClientLog .= "WARNING RESULTS WERE NOT SENT TO ROX CENTER.\nThis is due to previously logged errors.\n";
			return;
		} else if (empty($this->currentTestSuite)) {
			// nothing to do
			return;
		}
		try {
			$payload = array();

			// set test run UID
			$payload['u'] = $this->testsRunUid;

			// set test run duration
			$endTime = intval(microtime(true) * 1000); // UNIX timestamp in ms
			$payload['d'] = ($endTime - $this->testSuiteStartTime);

			// set project infos
			$payload['r'] = array(array());

			// set project API identifier
			if (isset($this->config['project']['apiId'])) {
				$payload['r'][0]['j'] = $this->config['project']['apiId'];
			} else {
				throw new RoxClientException("ERROR missing apiId for project in config files.");
			}

			// set project version
			if (isset($this->config['project']['version'])) {
				$payload['r'][0]['v'] = $this->config['project']['version'];
			} else {
				throw new RoxClientException("ERROR missing version for project in config files.");
			}

			// set test results
			$payload['r'][0]['t'] = $this->currentTestSuite;

			// convert payload in UTF-8
			$utf8Payload = $this->convertEncoding($payload, self::PAYLOAD_ENCODING);

			// publish payload
			if ($this->config['payload']['publish']) {
				$jsonPayload = json_encode($utf8Payload);
				$request = $this->httpClient->post($this->testsPayloadUrl, null, $jsonPayload, array("exceptions" => false));
				$response = $request->send();
				if ($response->getStatusCode() == 202) {
					$this->roxClientLog .= "INFO {$this->nbOfRoxableTests} test results successfully sent to ROX center ({$this->testsPayloadUrl}) out of {$this->nbOfTests} tests.\n";
				} else {
					$this->roxClientLog .= "ERROR ROX server ({$this->testsPayloadUrl}) returned an HTTP {$response->getStatusCode()} error:\n{$response->getBody(true)}\n";
				}
			} else {
				$this->roxClientLog .= "WARNING RESULTS WERE NOT SENT TO ROX CENTER.\nThis is due to 'publish' parameters in config file or to ROX_PUBLISH environment variable.\n";
			}

			// save payload
			if (isset($this->config['payload']['save']) && $this->config['payload']['save']) {
				if (!isset($this->config['workspace'])) {
					throw new RoxClientException("ERROR no 'workspace' parameter in config files. Could not save payload.");
				}
				$payloadDirPath = "{$this->config['workspace']}/phpunit/servers/{$this->config['server']}";
				if (!file_exists($payloadDirPath)) {
					mkdir($payloadDirPath, 0755, true);
				}
				if (file_put_contents($payloadDirPath . "/payload.json", $jsonPayload)) {
					$this->roxClientLog .= "INFO payload saved in workspace.\n";
				} else {
					throw new RoxClientException("ERROR unable to save payload in workspace");
				}
			}

			// print payload for DEBUG purpose
			if (isset($this->config['payload']['print']) && $this->config['payload']['print']) {
				$jsonPretty = new \Camspiers\JsonPretty\JsonPretty;
				$jsonPrettyPayload = $jsonPretty->prettify($utf8Payload);
				$this->roxClientLog .= "DEBUG generated JSON payload:\n$jsonPrettyPayload\n";
			}
		} catch (RoxClientException $e) {
			$this->roxClientLog .= $e->getMessage() . "\n";
		}
	}

	/**
	 * Recursively convert encoding.
	 */
	private function convertEncoding($data, $encoding) {
		if (is_string($data)) {
			return mb_convert_encoding($data, $encoding);
		} else if (is_array($data)) {
			$res = array();
			foreach ($data as $key => $value) {
				$res[$key] = $this->convertEncoding($value, $encoding);
			}
			return $res;
		} else {
			return $data;
		}
	}

	public function startTest(\PHPUnit_Framework_Test $test) {
		$this->currentTest = null;
		$this->nbOfTests += 1;
		if (!RoxClientException::exceptionOccured()) {
			$testName = $test->getName();
			$reflectionObject = new \ReflectionObject($test);
			$reflectionMethod = $reflectionObject->getMethod($testName);
			$annotations = $this->annotationReader->getMethodAnnotations($reflectionMethod);
			foreach ($annotations as $annotation) {
				if ($annotation instanceof RoxableTest) {
					// this is a roxable test
					try {
						$this->currentTest = array();
						$this->nbOfRoxableTests += 1;

						// set test key
						$this->currentTest['k'] = $annotation->getKey();

						// set test name
						$userDefinedTestName = $annotation->getName();
						if ($userDefinedTestName) {
							$this->currentTest['n'] = $userDefinedTestName;
						} else {
							$convertedTestName = preg_replace('/(?!^)[A-Z]{2,}(?=[A-Z][a-z])|[A-Z][a-z]/', ' $0', $testName);
							$this->currentTest['n'] = ucfirst(strtolower($convertedTestName));
						}

						// set default for passed
						$this->currentTest['p'] = true;

						// set flags
						$flag = $annotation->getFlags();
						if ($flag > 0){
							$this->currentTest['f'] = $flag;
						}

						// set category
						$userProposedCategory = $annotation->getCategory();
						if ($userProposedCategory) {
							$this->currentTest['c'] = $userProposedCategory;
						} else if (isset($this->config['project']['category'])) {
							$this->currentTest['c'] = $this->config['project']['category'];
						}

						// set tags
						if (isset($this->config['project']['tags'])) {
							$allTags = $this->config['project']['tags'];
						} else {
							$allTags = array();
						}
						$testTags = $annotation->getTags();
						if ($testTags) {
							foreach ($testTags as $tag) {
								if (!in_array($tag, $allTags)) {
									array_push($allTags, $tag);
								}
							}
						}
						if (!empty($allTags)) {
							$this->currentTest['g'] = $allTags;
						}

						// set tickets
						if (isset($this->config['project']['tickets'])) {
							$allTickets = $this->config['project']['tickets'];
						} else {
							$allTickets = array();
						}
						$testTickets = $annotation->getTickets();
						if ($testTickets) {
							foreach ($testTickets as $ticket) {
								if (!in_array($ticket, $allTickets)) {
									array_push($allTickets, $ticket);
								}
							}
						}
						if (!empty($allTickets)) {
							$this->currentTest['t'] = $allTickets;
						}
					} catch (RoxClientException $e) {
						$this->roxClientLog .= $e->getMessage() . "\n";
					}
				}
			}
		}
	}

	public function startTestSuite(\PHPUnit_Framework_TestSuite $suite) {
		$this->currentTestSuite = array();
		$this->nbOfRoxableTests = 0;
		$this->nbOfTests = 0;
	}

	/**
	 * From http://php.net//manual/en/exception.gettraceasstring.php
	 * 
	 * jTraceEx() - provide a Java style exception trace
	 * @param $exception
	 * @param $seen      - array passed to recursive calls to accumulate trace lines already seen
	 *                     leave as NULL when calling this function
	 * @return array of strings, one entry per trace line
	 */
	private function jTraceEx($e, $seen = null) {
		$starter = $seen ? 'Caused by: ' : '';
		$result = array();
		if (!$seen)
			$seen = array();
		$trace = $e->getTrace();
		$prev = $e->getPrevious();
		$result[] = sprintf('%s%s: %s', $starter, get_class($e), $e->getMessage());
		$file = $e->getFile();
		$line = $e->getLine();
		while (true) {
			$current = "$file:$line";
			if (is_array($seen) && in_array($current, $seen)) {
				$result[] = sprintf(' ... %d more', count($trace) + 1);
				break;
			}
			$result[] = sprintf(' at %s%s%s(%s%s%s)', count($trace) && array_key_exists('class', $trace[0]) ? str_replace('\\', '.', $trace[0]['class']) : '', count($trace) && array_key_exists('class', $trace[0]) && array_key_exists('function', $trace[0]) ? '.' : '', count($trace) && array_key_exists('function', $trace[0]) ? str_replace('\\', '.', $trace[0]['function']) : '(main)', $line === null ? $file : basename($file), $line === null ? '' : ':', $line === null ? '' : $line);
			if (is_array($seen))
				$seen[] = "$file:$line";
			if (!count($trace))
				break;
			$file = array_key_exists('file', $trace[0]) ? $trace[0]['file'] : 'Unknown Source';
			$line = array_key_exists('file', $trace[0]) && array_key_exists('line', $trace[0]) && $trace[0]['line'] ? $trace[0]['line'] : null;
			array_shift($trace);
		}
		$result = join("\n", $result);
		if ($prev)
			$result .= "\n" . jTraceEx($prev, $seen);

		return $result;
	}

	function __destruct() {
		if (!empty($this->roxClientLog)) {
			print "\n\n{$this->roxClientLog}\n\n";
		}
	}

}
