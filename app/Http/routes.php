<?php

/**
 * @var $router FluentSupport\Framework\Http\Router
 */

$router->prefix('imap-settings')->withPolicy('FluentSupport\App\Http\Policies\AdminSettingsPolicy')->group(function ($router) {
    $router->get('/logs', 'FSImap\App\Http\Controllers\ImapSettingsController@getLogs');
    $router->post('/logs/clear', 'FSImap\App\Http\Controllers\ImapSettingsController@clearLogs');
    $router->get('/verbose', 'FSImap\App\Http\Controllers\ImapSettingsController@getVerbose');
    $router->post('/verbose', 'FSImap\App\Http\Controllers\ImapSettingsController@setVerbose');
    $router->get('/{box_id}/config', 'FSImap\App\Http\Controllers\ImapSettingsController@getConfig')->int('box_id');
    $router->post('/{box_id}/config', 'FSImap\App\Http\Controllers\ImapSettingsController@saveConfig')->int('box_id');
    $router->post('/{box_id}/test', 'FSImap\App\Http\Controllers\ImapSettingsController@testConnection')->int('box_id');
    $router->post('/{box_id}/fetch-now', 'FSImap\App\Http\Controllers\ImapSettingsController@fetchNow')->int('box_id');
});
