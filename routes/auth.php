<?php

use Glueful\Http\Router;
use Glueful\Controllers\AuthController;

$authController = new AuthController();

// Auth routes
Router::addRoute('POST', 'auth/login', function() use ($authController) {
    return $authController->login();
}, true);

Router::addRoute('POST', 'auth/verify-email', function() use ($authController) {
    return $authController->verifyEmail();
}, true);

Router::addRoute('POST', 'auth/verify-otp', function() use ($authController) {
    return $authController->verifyOtp();
}, true);

Router::addRoute('POST', 'auth/forgot-password', function() use ($authController) {
    return $authController->forgotPassword();
}, true);

Router::addRoute('POST', 'auth/reset-password', function() use ($authController) {
    return $authController->resetPassword();
}, true);

Router::addRoute('POST', 'auth/validate-token', function() use ($authController) {
    return $authController->validateToken();
}); // Not public, requires token

Router::addRoute('POST', 'auth/refresh-token', function() use ($authController) {
    return $authController->refreshToken();
}, true);

Router::addRoute('POST', 'auth/logout', function($params) use ($authController) {
    return $authController->logout();
});