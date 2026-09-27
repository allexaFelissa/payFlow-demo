<?php

use Illuminate\Support\Facades\Route;

// React Router owns every browser route. API endpoints continue to live in routes/api.php.
Route::view('/{path?}', 'app')->where('path', '.*');
