@echo off
REM Registers a Windows Task Scheduler job that runs the BuildSmart cleanup every 30 minutes.
REM Right-click this file and choose "Run as administrator". Edit the two paths if XAMPP / the project live elsewhere.
set PHP=C:\xampp\php\php.exe
set SCRIPT=C:\xampp\htdocs\BuildSmart\cron\release_stale_orders.php
schtasks /Create /TN "BuildSmart Release Stale Orders" /SC MINUTE /MO 30 /RU SYSTEM /F /TR "\"%PHP%\" \"%SCRIPT%\""
if errorlevel 1 (echo Could not create the task. Run this file as administrator.) else (echo Task created. Test it with: schtasks /Run /TN "BuildSmart Release Stale Orders")
pause
