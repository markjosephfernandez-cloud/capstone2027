@echo off
cd /d "%~dp0"
echo Starting Greyhound ConvNeXt AI service...
.venv_ai\Scripts\python.exe api_server.py
pause
