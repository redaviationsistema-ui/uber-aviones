#!/bin/sh

set -eu

BASE_URL="${BASE_URL:-http://localhost:8000}"
REGISTER_URL="${BASE_URL}/api/v1/auth/register"
LOGIN_URL="${BASE_URL}/api/v1/auth/login"
CREATE_REQUEST_URL="${BASE_URL}/api/v1/cliente/solicitudes"
CHECKOUT_URL="${BASE_URL}/api/v1/cliente/stripe/checkout/create"

TEST_EMAIL="${TEST_EMAIL:-stripe-local-$(date +%s)@example.com}"
TEST_PASSWORD="${TEST_PASSWORD:-StripeLocal1234}"
TEST_NAME="${TEST_NAME:-Stripe Local Client}"
TEST_PHONE="${TEST_PHONE:-+525500000000}"
TEST_ORIGIN="${TEST_ORIGIN:-MMMX}"
TEST_DESTINATION="${TEST_DESTINATION:-MMUN}"
TEST_PASSENGERS="${TEST_PASSENGERS:-2}"
TEST_DEPARTURE="${TEST_DEPARTURE:-2026-06-15 10:00:00}"
TEST_AMOUNT="${TEST_AMOUNT:-2500}"
TEST_CURRENCY="${TEST_CURRENCY:-USD}"

parse_json() {
    php -r '
        $json = stream_get_contents(STDIN);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            fwrite(STDERR, "No se pudo parsear JSON.\n".$json."\n");
            exit(1);
        }
        $path = explode(".", $argv[1]);
        $value = $data;
        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                fwrite(STDERR, "No se encontro la clave ".$argv[1].".\n".$json."\n");
                exit(1);
            }
            $value = $value[$segment];
        }
        if (is_array($value)) {
            echo json_encode($value, JSON_UNESCAPED_SLASHES);
            exit(0);
        }
        echo (string) $value;
    ' "$1"
}

post_json() {
    url="$1"
    body="$2"
    token="${3:-}"

    response_file="$(mktemp)"
    http_code_file="$(mktemp)"

    if [ -n "$token" ]; then
        curl -sS -o "$response_file" -w "%{http_code}" \
            -H "Accept: application/json" \
            -H "Content-Type: application/json" \
            -H "Authorization: Bearer $token" \
            -X POST "$url" \
            -d "$body" > "$http_code_file"
    else
        curl -sS -o "$response_file" -w "%{http_code}" \
            -H "Accept: application/json" \
            -H "Content-Type: application/json" \
            -X POST "$url" \
            -d "$body" > "$http_code_file"
    fi

    http_code="$(cat "$http_code_file")"
    response="$(cat "$response_file")"
    rm -f "$response_file" "$http_code_file"

    printf "%s\n%s" "$http_code" "$response"
}

echo "Preparando prueba Stripe local contra ${BASE_URL}"

if grep -q '^DB_HOST=' .env 2>/dev/null; then
    db_host="$(grep '^DB_HOST=' .env | cut -d= -f2- || true)"
    app_env="$(grep '^APP_ENV=' .env | cut -d= -f2- || true)"
    echo "APP_ENV actual: ${app_env:-desconocido}"
    echo "DB_HOST actual: ${db_host:-desconocido}"
    if [ -n "${db_host:-}" ] && [ "$db_host" != "127.0.0.1" ] && [ "$db_host" != "localhost" ]; then
        echo "Aviso: esta prueba escribira en una base de datos no local."
    fi
fi

register_payload="$(cat <<JSON
{"name":"$TEST_NAME","email":"$TEST_EMAIL","password":"$TEST_PASSWORD","phone":"$TEST_PHONE","role":"client"}
JSON
)"

register_result="$(post_json "$REGISTER_URL" "$register_payload")"
register_code="$(printf "%s" "$register_result" | sed -n '1p')"
register_body="$(printf "%s" "$register_result" | sed -n '2,$p')"

if [ "$register_code" = "201" ]; then
    auth_body="$register_body"
    echo "Cliente de prueba registrado: $TEST_EMAIL"
elif [ "$register_code" = "422" ]; then
    echo "El correo ya existe; iniciando sesion con $TEST_EMAIL"
    login_payload="$(cat <<JSON
{"email":"$TEST_EMAIL","password":"$TEST_PASSWORD"}
JSON
)"
    login_result="$(post_json "$LOGIN_URL" "$login_payload")"
    login_code="$(printf "%s" "$login_result" | sed -n '1p')"
    login_body="$(printf "%s" "$login_result" | sed -n '2,$p')"
    if [ "$login_code" != "200" ]; then
        echo "No se pudo iniciar sesion."
        printf "%s\n" "$login_body"
        exit 1
    fi
    auth_body="$login_body"
else
    echo "No se pudo registrar el cliente."
    printf "%s\n" "$register_body"
    exit 1
fi

token="$(printf "%s" "$auth_body" | parse_json token)"
user_id="$(printf "%s" "$auth_body" | parse_json user.id)"

request_payload="$(cat <<JSON
{"origin":"$TEST_ORIGIN","destination":"$TEST_DESTINATION","departure_datetime":"$TEST_DEPARTURE","passengers":$TEST_PASSENGERS,"trip_type":"one_way","notes":"Prueba local Stripe CLI"}
JSON
)"

request_result="$(post_json "$CREATE_REQUEST_URL" "$request_payload" "$token")"
request_code="$(printf "%s" "$request_result" | sed -n '1p')"
request_body="$(printf "%s" "$request_result" | sed -n '2,$p')"

if [ "$request_code" != "201" ]; then
    echo "No se pudo crear la solicitud."
    printf "%s\n" "$request_body"
    exit 1
fi

flight_request_id="$(printf "%s" "$request_body" | parse_json flight_request.id)"

php artisan tinker --execute="
\$solicitud = \App\Modelos\SolicitudVuelo::find(${flight_request_id});
if (!\$solicitud) { throw new RuntimeException('Solicitud no encontrada.'); }
\$solicitud->update([
    'final_price' => ${TEST_AMOUNT},
    'currency' => '${TEST_CURRENCY}',
    'pricing_context' => ['selected_card_price' => ${TEST_AMOUNT}, 'final_price' => ${TEST_AMOUNT}],
    'payment_status' => 'pending',
]);
echo \$solicitud->id;
" --no-interaction --quiet >/dev/null

checkout_payload="$(cat <<JSON
{"flight_request_id":$flight_request_id,"contact_email":"$TEST_EMAIL"}
JSON
)"

checkout_result="$(post_json "$CHECKOUT_URL" "$checkout_payload" "$token")"
checkout_code="$(printf "%s" "$checkout_result" | sed -n '1p')"
checkout_body="$(printf "%s" "$checkout_result" | sed -n '2,$p')"

if [ "$checkout_code" != "200" ]; then
    echo "No se pudo crear el checkout de Stripe."
    printf "%s\n" "$checkout_body"
    exit 1
fi

checkout_session_id="$(printf "%s" "$checkout_body" | parse_json checkout_session_id)"
checkout_url_value="$(printf "%s" "$checkout_body" | parse_json checkout_url)"

echo
echo "Listo para prueba end to end."
echo "Usuario: $TEST_EMAIL"
echo "Password: $TEST_PASSWORD"
echo "User ID: $user_id"
echo "Flight Request ID: $flight_request_id"
echo "Checkout Session ID: $checkout_session_id"
echo "Checkout URL: $checkout_url_value"
echo
echo "Siguiente paso:"
echo "1. Deja corriendo: php artisan serve"
echo "2. Deja corriendo: sh scripts/stripe-listen-local.sh"
echo "3. Abre el Checkout URL y paga con 4242 4242 4242 4242"
echo "4. Verifica el webhook con:"
echo "   php artisan tinker --execute=\"dump(DB::table('webhook_events')->latest('id')->first()); dump(DB::table('payments')->latest('id')->first()); dump(DB::table('flight_requests')->find($flight_request_id));\""
