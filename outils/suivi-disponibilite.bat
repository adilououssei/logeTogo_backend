@echo off
rem Suivi de la disponibilite des annonces (rappels au 7e jour, retrait au 14e sans reponse).
rem Lance chaque jour par le Planificateur de taches Windows (tache « LogeTogo - Suivi disponibilite »).
rem Le compte rendu de chaque passage est ajoute a var\log\suivi-disponibilite.log.
cd /d "%~dp0.."
"C:\laragon\bin\php\php-8.4.22-nts-Win32-vs17-x64\php.exe" bin\console app:annonces:suivi-disponibilite --no-interaction >> var\log\suivi-disponibilite.log 2>&1
