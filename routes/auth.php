<?php

use Glueful\Http\Router;
use Glueful\Controllers\AuthController;
use Symfony\Component\HttpFoundation\Request;

$authController = new AuthController();

// Auth routes
Router::group('/auth',function() use ($authController) {
    Router::post('/login', function (Request $request) use ($authController){
        return $authController->login();
    });
    Router::post('/verify-email', function() use ($authController) {
        return $authController->verifyEmail();
    });
    
    Router::post('/verify-otp', function() use ($authController) {
        return $authController->verifyOtp();
    });
    Router::post('/forgot-password', function() use ($authController) {
        return $authController->forgotPassword();
    });
    Router::post('/reset-password', function() use ($authController) {
        return $authController->resetPassword();
    });
    Router::post('/validate-token', function() use ($authController) {
        return $authController->validateToken();
    });
    Router::post('/refresh-token', function() use ($authController) {
        return $authController->refreshToken();
    });
    Router::post('/logout', function($params) use ($authController) {
        return $authController->logout();
    });
});
