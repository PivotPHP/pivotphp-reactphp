# Guia de Segurança - PivotPHP ReactPHP

## 🛡️ Práticas Obrigatórias de Segurança

Este documento define as práticas **OBRIGATÓRIAS** para uso seguro do PivotPHP ReactPHP em produção. O não cumprimento destas diretrizes pode resultar em vazamento de dados, instabilidade do servidor ou vulnerabilidades de segurança.

## 1. ❌ Código Bloqueante - NUNCA USE

### Funções Proibidas

```php
// ❌ NUNCA USE - Bloqueia todo o servidor
sleep(5);
usleep(1000000);
time_nanosleep(0, 500000000);

// ✅ USE SEMPRE - Não bloqueante
$loop->addTimer(5.0, function() {
    // Código executado após 5 segundos
});
```

### Operações de I/O

```php
// ❌ NUNCA USE - Bloqueante
$content = file_get_contents('https://api.exemplo.com/dados');
$result = curl_exec($ch);
$data = fread($file, 1024);

// ✅ USE SEMPRE - Assíncrono
use React\Http\Browser;
$browser = new Browser();
$browser->get('https://api.exemplo.com/dados')->then(function($response) {
    $content = (string) $response->getBody();
});
```

### Database

```php
// ❌ NUNCA USE - Bloqueante
$pdo = new PDO('mysql:host=localhost;dbname=test', 'user', 'pass');
$result = $pdo->query('SELECT * FROM users');

// ✅ USE SEMPRE - Pool assíncrono ou react/mysql
use React\MySQL\Factory;
$factory = new Factory();
$connection = $factory->createLazyConnection('user:pass@localhost/test');
```

## 2. 🔒 Isolamento de Estado

### Variáveis Globais

```php
// ❌ NUNCA USE - Estado compartilhado entre requests
$_SESSION['user_id'] = 123;
$GLOBALS['config'] = $config;
global $userCache;

// ✅ USE SEMPRE - Estado por request
$request = $request->withAttribute('user_id', 123);
$response = $response->withAttribute('config', $config);
```

### Variáveis Estáticas

```php
// ❌ EVITE - Acumula entre requests
class UserService {
    private static $cache = [];
    
    public function getUser($id) {
        self::$cache[$id] = $userData; // Vazamento!
    }
}

// ✅ USE COM LIMITE - Gerenciamento de memória
class UserService {
    private static $cache = [];
    private const MAX_CACHE_SIZE = 100;
    
    public function getUser($id) {
        if (count(self::$cache) > self::MAX_CACHE_SIZE) {
            self::$cache = array_slice(self::$cache, -50, null, true);
        }
        self::$cache[$id] = $userData;
    }
}
```

## 3. 💾 Gerenciamento de Memória

### Limites Obrigatórios

```php
// config/reactphp.php
return [
    'memory_guard' => [
        'max_memory' => 256 * 1024 * 1024, // 256MB máximo
        'warning_threshold' => 200 * 1024 * 1024, // Alerta em 200MB
        'auto_restart_threshold' => 300 * 1024 * 1024, // Restart em 300MB
    ],
];
```

### Limpeza de Recursos

```php
// ✅ SEMPRE limpe recursos grandes após uso
$largeData = processLargeFile($file);
// ... usar dados ...
unset($largeData); // Libera memória
gc_collect_cycles(); // Força coleta de lixo se necessário
```

### Streams e Arquivos

```php
// ✅ SEMPRE feche streams e arquivos
$stream->on('end', function() use ($stream) {
    $stream->close();
});

// ✅ USE streaming para arquivos grandes
$readStream = new ReadableResourceStream(fopen('large.csv', 'r'));
$readStream->on('data', function($chunk) {
    // Processa chunk por chunk, não carrega tudo na memória
});
```

## 4. 🚦 Rate Limiting e Proteções

### Configuração Obrigatória

```php
// Middleware de segurança DEVE ser adicionado
$app->use(new SecurityMiddleware(
    $app->make(RequestIsolation::class),
    $app->make(GlobalStateSandbox::class),
    [
        'rate_limit' => [
            'enabled' => true,
            'max_requests' => 100,
            'window_seconds' => 60,
        ],
        'max_request_size' => 10 * 1024 * 1024, // 10MB
        'timeout' => 30.0, // 30 segundos máximo
    ]
));
```

### Headers de Segurança

```php
// Automaticamente adicionados pelo SecurityMiddleware:
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-XSS-Protection: 1; mode=block
Referrer-Policy: strict-origin-when-cross-origin
Strict-Transport-Security: max-age=31536000 (em produção)
```

## 5. 🔍 Monitoramento Obrigatório

### Health Checks

```php
// Endpoint obrigatório de health check
$router->get('/health', function() use ($app) {
    $monitor = $app->make(HealthMonitor::class);
    $status = $monitor->getHealthStatus();
    
    $code = $status['status'] === 'healthy' ? 200 : 503;
    return Response::json($status, $code);
});
```

### Alertas Críticos

```php
// Configure alertas para condições críticas
$monitor = $app->make(HealthMonitor::class);
$monitor->onAlert(function($alert) use ($logger) {
    if ($alert['severity'] === 'critical') {
        // Notificar equipe imediatamente
        $logger->critical('ALERTA CRÍTICO', $alert);
        // Enviar SMS/Slack/Email
    }
});
```

## 6. 🚀 Deploy Seguro

### Supervisor Configuration

```ini
[program:pivotphp-reactphp]
command=php /path/to/server.php
autostart=true
autorestart=true
startretries=3
stderr_logfile=/var/log/pivotphp-reactphp.err.log
stdout_logfile=/var/log/pivotphp-reactphp.out.log
user=www-data
environment=APP_ENV="production"
```

### Graceful Shutdown

```php
// Server DEVE implementar shutdown gracioso
$loop->addSignal(SIGTERM, function() use ($server, $logger) {
    $logger->info('Iniciando shutdown gracioso...');
    $server->stop(); // Para de aceitar novas conexões
    // Aguarda conexões existentes terminarem
});
```

### Limites do Sistema

```bash
# /etc/security/limits.conf
www-data soft nofile 65536
www-data hard nofile 65536
www-data soft nproc 32768
www-data hard nproc 32768
```

## 7. 🐛 Debug e Desenvolvimento

### NUNCA em Produção

```php
// ❌ NUNCA em produção
var_dump($data); // Vai para console, não para response
die('debug'); // MATA O SERVIDOR INTEIRO!
exit(); // MATA O SERVIDOR INTEIRO!
xdebug_break(); // Congela o servidor

// ✅ USE logging apropriado
$logger->debug('Debug data', ['data' => $data]);
```

### Desenvolvimento Local

```php
// .env.local
APP_ENV=development
APP_DEBUG=true
REACTPHP_SECURITY_SCAN=false # Desabilita scan em dev
```

## 8. ⚡ Checklist de Produção

Antes de ir para produção, CONFIRME:

- [ ] Sem funções bloqueantes (`sleep`, `file_get_contents`, etc)
- [ ] Sem uso de `$_SESSION`, `$GLOBALS` ou variáveis globais
- [ ] Memória com limites configurados
- [ ] Rate limiting ativado
- [ ] Health check endpoint implementado
- [ ] Supervisor configurado para auto-restart
- [ ] Logs assíncronos configurados
- [ ] Graceful shutdown implementado
- [ ] Sem `var_dump`, `die` ou `exit` no código
- [ ] Timeouts configurados para todas operações externas

## 9. 🆘 Troubleshooting

### Servidor Congelado

```bash
# Verificar se está respondendo
curl -f http://localhost:8080/health || echo "Servidor não responde"

# Verificar processos bloqueados
strace -p $(pidof php) -e trace=futex,epoll_wait

# Forçar restart via supervisor
supervisorctl restart pivotphp-reactphp
```

### Vazamento de Memória

```bash
# Monitorar memória em tempo real
watch -n 1 'ps aux | grep php | grep -v grep'

# Analisar heap dump
jemalloc-prof php server.php
```

### Detectar Código Bloqueante

```php
// Execute antes do deploy
$detector = new BlockingCodeDetector();
$result = $detector->scanFile('src/Controller/ApiController.php');
if (!$result['summary']['safe']) {
    throw new Exception('Código bloqueante detectado!');
}
```

## 10. 📋 Configuração de Segurança Completa

```php
// config/reactphp.php
return [
    'server' => [
        'debug' => false,
        'streaming' => true,
        'max_concurrent_requests' => 1000,
        'request_body_size_limit' => 10 * 1024 * 1024, // 10MB
    ],
    
    'security' => [
        'enable_isolation' => true,
        'enable_sandbox' => true,
        'scan_blocking_code' => true,
        'scan_paths' => ['app', 'src'],
    ],
    
    'memory_guard' => [
        'max_memory' => 256 * 1024 * 1024,
        'warning_threshold' => 200 * 1024 * 1024,
        'gc_threshold' => 100 * 1024 * 1024,
        'check_interval' => 10.0,
        'leak_detection_enabled' => true,
    ],
    
    'monitoring' => [
        'thresholds' => [
            'memory_usage_percent' => 80,
            'avg_response_time_ms' => 100,
            'error_rate_percent' => 5,
            'event_loop_lag_ms' => 50,
        ],
    ],
];
```

---

⚠️ **AVISO IMPORTANTE**: O ReactPHP opera de forma fundamentalmente diferente do PHP tradicional. O não cumprimento destas diretrizes pode resultar em **perda de dados**, **instabilidade do servidor** ou **vulnerabilidades de segurança graves**. 

Em caso de dúvidas, sempre escolha a opção mais segura ou consulte a documentação oficial do ReactPHP.