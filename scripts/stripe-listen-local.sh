#!/bin/sh

set -eu

FORWARD_URL="${1:-http://localhost:8000/api/v1/stripe/webhook}"

echo "Escuchando eventos de Stripe y reenviando a: ${FORWARD_URL}"
echo "Copia el valor whsec_... y actualiza STRIPE_WEBHOOK_SECRET en .env"

stripe listen --forward-to "${FORWARD_URL}"
