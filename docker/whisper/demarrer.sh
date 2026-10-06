#!/bin/sh
# Démarrage du serveur Whisper. Les modèles sont téléchargés au premier lancement dans le
# volume /modeles, puis réutilisés :
#   - ggml-base-q5_1.bin (57 Mo) : reconnaissance du français, rapide sur processeur ;
#   - ggml-silero-v5.1.2.bin (1 Mo) : détection de la parole (les silences sont sautés).
set -e
MODELE=/modeles/ggml-base-q5_1.bin
VAD=/modeles/ggml-silero-v5.1.2.bin
[ -f "$MODELE" ] || curl -fsSL -o "$MODELE" https://huggingface.co/ggerganov/whisper.cpp/resolve/main/ggml-base-q5_1.bin
[ -f "$VAD" ] || curl -fsSL -o "$VAD" https://huggingface.co/ggml-org/whisper-vad/resolve/main/ggml-silero-v5.1.2.bin
exec whisper-server -m "$MODELE" --host 0.0.0.0 --port 8080 -l fr -t "${WHISPER_THREADS:-2}" -bo 1 -nt --vad -vm "$VAD"
