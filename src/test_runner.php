<?php

/*
 * The MIT License
 *
 * Copyright 2016 Richard Jedlička <jedlicka.r@gmail.com> (http://uiii.cz)
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

require_once __DIR__ . '/util/log.php';
require_once __DIR__ . '/util/path.php';
require_once __DIR__ . '/installer.php';

use t1st3\JSONMin\JSONMin;

class TestRunner
{
	const ACTION_CONTINUE = "Continue";
	const ACTION_STOP = "Stop";
	const ACTION_REPEAT = "Repeat";

	protected $installer;

	public function __construct($configFile) {
		Log::info("Initializing ...");

		$this->config = $this->loadConfig($configFile);
		$this->installer = new Installer($this->config);
	}

	public function run() {
		foreach($this->config->testTags as $tagName) {
			$nextAction = $this->testProcessWire($tagName);

			while ($nextAction === self::ACTION_REPEAT) {
				$nextAction = $this->testProcessWire($tagName);
			}

			if ($nextAction === self::ACTION_STOP) {
				break;
			}
		}
	}

	protected function loadConfig($configFile) {
		if (! file_exists($configFile)) {
			Log::error("Missing config! Please, create pw-test.json config file.");
			die();
		}

		$defaultConfigFile = Path::join(__DIR__, '../conf/pw-test.json');
		$defaultConfig = json_decode(JSONMin::minify(file_get_contents($defaultConfigFile)), true);
		$config = json_decode(JSONMin::minify(file_get_contents($configFile)), true);

		$config = json_decode(json_encode(array_replace_recursive($defaultConfig, $config)));

		// fix: when the copySources option is empty object
		// it is decoded as an empty array but must be an object
		if (! $config->copySources) {
			$config->copySources = new \stdClass;
		}

		$validator = new JsonSchema\Validator;
		$validator->check($config, (object)['$ref' => 'file://' . Path::join(__DIR__, '../conf/schema.json')]);

		if (! $validator->isValid()) {
			$errorMessages = [];
			foreach ($validator->getErrors() as $error) {
				array_push($errorMessages, sprintf("%s: %s", $error['property'], $error['message']));
			}

			Log::error(sprintf(
				"Configuration file contains errors:%s%s",
				PHP_EOL,
				implode(PHP_EOL, $errorMessages)
			));

			die();
		}

		$config->_file = $configFile;

		// absolutize tmpDir
		if (! Path::isAbsolute($config->tmpDir)) {
			// make the tmpDir relative to the directory where the config file is stored
			$config->tmpDir = Path::join(dirname($configFile), trim($config->tmpDir));
		}

		return $config;
	}

	protected function testProcessWire($tagName) {
		Log::info(PHP_EOL . "::: Testing against ProcessWire $tagName :::" . PHP_EOL);

		$processWirePath = Path::join($this->config->tmpDir, "pw");
		$testSuccess = false;

		try {
			$this->installer->installProcessWire($tagName, $processWirePath);
			$this->copySourceFiles($processWirePath);

			$testSuccess = $this->runTests($processWirePath);
		} catch (\Exception $e) {
			Log::error($e->getMessage() . PHP_EOL);
		}

		$action = $this->askForAction($testSuccess, $processWirePath);

		Log::info(sprintf("Clean up & %s", $action));

		$this->installer->uninstallProcessWire($processWirePath);

		return $action;
	}

	protected function copySourceFiles($processWirePath) {
		foreach ($this->config->copySources as $destination => $sources) {
			if (is_array($sources)) {
				foreach($sources as $source) {
					$source = trim($source);

					Path::copy(
						Path::join(dirname($this->config->_file), $source),
						Path::join($processWirePath, trim($destination), basename($source))
					);
				}
			} else {
				Path::copy(
					Path::join(dirname($this->config->_file), trim($sources)),
					Path::join($processWirePath, $destination)
				);
			}
		}
	}

	protected function runTests($processWirePath) {
		Log::info("Running tests ..." . PHP_EOL);

		list($cmdExecutable, $args) = preg_split("/\s+/", trim($this->config->testCmd) . " ", 2);

		if (strpbrk($cmdExecutable, "/\\") !== false) {
			// cmd executable is a path, so make it absolute
			$cmdExecutable = Path::join(dirname($this->config->_file), $cmdExecutable);
		}

		$env = [
			"PW_PATH" => $processWirePath
		];

		$result = Cmd::run($cmdExecutable, preg_split("/\s+/", $args), [
			'env' => $env,
			'throw_on_error' => false,
			'print_output' => true
		]);

		Log::info(PHP_EOL);

		$success = $result->exitCode === 0;

		if (! $success) {
			Log::info("Tests failed" . PHP_EOL);
		}

		return $success;
	}

	protected function askForAction($testSuccess, $processWirePath) {
		$waitAfterTests = $this->config->waitAfterTests;

		$neverWait = $waitAfterTests === "never";
		$waitOnFailureButSuccess = $waitAfterTests === "onFailure" && $testSuccess;

		if ($neverWait || $waitOnFailureButSuccess) {
			return self::ACTION_CONTINUE;
		}

		Log::info(sprintf(
			"Test runner is now halted (configured to wait after %s tests, see 'waitAfterTests' option)",
			$waitAfterTests === "always" ? "all" : "failed"
		));

		if ($processWirePath) {
			Log::info("Tested ProcessWire instance is installed in '$processWirePath'");
		}

		$options = [
			self::ACTION_CONTINUE => "Yes",
			self::ACTION_STOP => "No"
		];

		$defaultAction = self::ACTION_CONTINUE;

		if (! $testSuccess) {
			$options[self::ACTION_REPEAT] = "Repeat";
			$defaultAction = self::ACTION_STOP;
		}

		$selectedAction = null;

		while (! $selectedAction) {
			echo sprintf(
				"Do you want to continue? %s (default is [%s]): ",
				implode("  ", array_map(function ($option) {
					return preg_replace("/^./", "[$0]", $option);
				}, $options)),
				$options[$defaultAction][0]
			);

			$input = trim(fgets(STDIN));

			if (! $input) {
				$selectedAction = $defaultAction;
				break;
			}

			foreach ($options as $action => $option) {
				if (stripos($option, $input) === 0) {
					$selectedAction = $action;
				}
			}

			if (! $selectedAction) {
				Log::error(sprintf("Unknown option: %s" . PHP_EOL, $input));
			}
		}

		echo PHP_EOL;

		return $selectedAction;
	}
}