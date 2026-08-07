@echo off
cd /d "%~dp0"
echo Starting Greyhound Hub 2.0 AI service...
.venv_ai\Scripts\python.exe api_server.py
pause
