# Ip2Location Bundle

Symfony Bundle for the [ip2location/ip2location-io-php](https://github.com/ip2location/ip2location-io-php) library, to get data from an IP address.

First, get an API key at https://www.ip2location.io/pricing and add it to .env.local

```bash
echo "IP2LOCATION_API_KEY=my-api-key" >> .env.local
composer req survos/ip2location-bundle
bin/console debug:config survos_ip2_location --format=yaml > config/packages/survos_ip2_location.yaml

```

```twig
{{ ipGeolocation(app.request.clientIp).country_code}}
```

By default, the bundle gets the API key from the environment.
Since localhost doesn't have geolocation data, you can set a default.
If you're running locally, the remote address is localhost.  Get your real IP address at whatismyip.com or https://api.ipify.org?format=json

```yaml
# config/packages/survos_ip2location.yaml
survos_ip2_location:
  api_key: '%env(IP2LOCATION_API_KEY)%'
  localhost_ip: 8.8.8.8
  
```

## Trivial but functional application

Requirements:

* Locally installed PHP 8
* Symfony CLI
* sed (to change /app to / without opening an editor)
* API Key 

```bash
symfony new Ip2locationDemo --webapp && cd Ip2locationDemo
echo "IP2LOCATION_API_KEY=my-api-key" >> .env.local
symfony composer req survos/ip2location-bundle
symfony console make:controller AppController
sed -i "s|/app|/|" src/Controller/AppController.php 

cat <<'EOF' > templates/app/index.html.twig
{% extends 'base.html.twig' %}
{% block body %}
{% set ip = app.request.clientIp %}
{{ isLocalhost(ip) ? "<div>Localhost has no geolocation, using value from config</div>" }}
Hello, visitor from {{ ipGeolocation(ip).country_name}} )
<pre>{{ ipGeolocation(ip)|json_encode(constant('JSON_PRETTY_PRINT')) }}</pre>

Powered by IP2Location.io <a href="https://www.ip2location.io">IP geolocation</a> web service.

{% endblock %}
EOF

symfony server:start -d
symfony open:local
```
