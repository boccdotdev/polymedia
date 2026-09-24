<?php
/**
 * Keep PHPUnit's error handler when a test creates a lightweight Yii app.
 * Otherwise Yii turns dependency deprecations into exceptions and leaks its
 * handler into subsequent tests.
 */
define('YII_ENABLE_ERROR_HANDLER', false);

require dirname(__DIR__) . '/vendor/autoload.php';
require_once \Composer\InstalledVersions::getInstallPath('yiisoft/yii2') . '/Yii.php';
require_once \Composer\InstalledVersions::getInstallPath('craftcms/cms') . '/src/Craft.php';
