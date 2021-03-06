<?php

require_once __DIR__ . "/../bootstrap.php";

require_once SRC_DIR . "/util/path.php";

define("DS", DIRECTORY_SEPARATOR);

use Tester\Assert;

Assert::same(Path::join("a\\", "b", "c"), "a" . DS . "b" . DS . "c");
Assert::same(Path::join("a/", "\b/", "c"), "\b" . DS . "c");
Assert::same(Path::join("a\\", "/b", "c/"), "/b" . DS . "c");
Assert::same(Path::join("a", "C:\b", "c"), "C:\b" . DS . "c");