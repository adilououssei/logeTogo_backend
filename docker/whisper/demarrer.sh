#!/bin/sh
# Serveur de transcription vocale. Les modèles sont téléchargés au premier démarrage
# dans le volume /modeles (gardés ensuite) :
#   - ggml-base-q5_1.bin (57 Mo) : français, rapide sur processeur ;
#   - ggml-silero-v5.1.2.bin (1 Mo) : détection de la parole (les silences ne sont pas transcrits).
set -e
MODELE=/modeles/ggml-base-q5_1.bin
VAD=/modeles/ggml-silero-v5.1.2.bin
[ -f "$MODELE" ] || curl -fsSL -o "$MODELE" https://huggingface.co/ggerganov/whisper.cpp/resolve/main/ggml-base-q5_1.bin
[ -f "$VAD" ] || curl -fsSL -o "$VAD" https://huggingface.co/ggml-org/whisper-vad/resolve/main/ggml-silero-v5.1.2.bin
exec whisper-server -m "$MODELE" --host 0.0.0.0 --port 8080 -l fr -t "${WHISPER_THREADS:-2}" -bo 1 -nt --vad -vm "$VAD"
