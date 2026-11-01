<?php

use Illuminate\Support\Facades\Route;
use Passioncorners\UpKeepAgent\Http\AgentController;

/*
 * Outside the "web" group on purpose: no session, no cookies, no CSRF. Every monitored
 * app keeps sessions in its database, so a web route would write a row every minute.
 */
Route::get('upkeep-agent', AgentController::class)->name('upkeep-agent');
