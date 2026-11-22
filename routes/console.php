<?php

use Illuminate\Support\Facades\Schedule;

// Safety net for webhooks that never arrive.
Schedule::command('pixelforge:reconcile')->everyMinute()->withoutOverlapping();
