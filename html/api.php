<?php
# vim: syntax=php tabstop=4 softtabstop=0 noexpandtab laststatus=1 ruler

/**
 * html/api.php
 *
 * REST API entry point for TeraLab.
 *
 * @author Andrea Dainese <andrea.dainese@gmail.com>
 * @copyright 2014-2016 Andrea Dainese
 * @license https://opensource.org/licenses/BSD-3-Clause
 * @link http://www.unetlab.com/
 * @version 20160719
 *
 * Modified by TeraLab contributors, 2026-11-17.
 * The Slim 2 router was retired; this entry point now runs the Slim 4 API
 * (v2 plus the v1 compatibility adapter). The modifications are licensed
 * under AGPL-3.0-or-later.
 */

declare(strict_types=1);

$autoload = dirname(__DIR__).'/api/vendor/autoload.php';
if (!is_file($autoload)) {
	http_response_code(500);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode([
		'code' => 500,
		'status' => 'error',
		'message' => 'TeraLab API dependencies are not installed.',
	]);
	exit;
}

require $autoload;

try {
	(new \TeraLab\Api\App())->create()->run();
} catch (Throwable $exception) {
	error_log('TeraLab API bootstrap failure: '.$exception -> getMessage());
	http_response_code(500);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode([
		'code' => 500,
		'status' => 'error',
		'message' => 'Internal server error.',
	]);
}
