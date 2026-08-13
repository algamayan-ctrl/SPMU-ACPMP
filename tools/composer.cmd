@echo off
set "COMPOSER_HOME=%~dp0..\..\..\work\runtime\composer-home"
set "COMPOSER_CACHE_DIR=%~dp0..\..\..\work\runtime\composer-cache"
"%~dp0..\..\..\work\runtime\php\php.exe" "%~dp0..\..\..\work\runtime\composer.phar" %*
