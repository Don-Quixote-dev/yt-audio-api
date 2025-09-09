# yt-audio-api 🎵

API simples em PHP + `yt-dlp` para extrair URL de áudio direto de vídeos do YouTube.  
Pensado para integração com serviços como a API de Transcrição da OpenAI.

## 🚀 Como funciona
- Recebe a URL de um vídeo do YouTube via parâmetro `url`.
- Usa `yt-dlp` para extrair o link direto de áudio.
- Retorna JSON com o `video_url` e o `audio_url` (temporário).

## 🔧 Exemplo de uso
GET /get-audio.php?url=https://www.youtube.com/watch?v=dQw4w9WgXcQ

Resposta:
```json
{
  "video_url": "https://www.youtube.com/watch?v=dQw4w9WgXcQ",
  "audio_url": "https://rr3---sn-...-googlevideo.com/videoplayback?...&expire=..."
}

⚠️ Observação: os links de áudio expiram em poucas horas, então use imediatamente.

📦 Deploy no Render

Este projeto foi feito para rodar no Render
 usando Docker.
Basta conectar o repositório e fazer o deploy.

