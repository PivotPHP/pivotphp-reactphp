# 📈 Guia de Migração - PivotPHP ReactPHP v0.1.0

Este guia ajuda na migração de versões anteriores (0.0.x) para a versão estável 0.1.0.

## 🎯 Visão Geral da Migração

A versão 0.1.0 é uma release estável com melhorias significativas, mas mantém **100% de compatibilidade** com a API pública das versões 0.0.x.

### **Principais Mudanças**
- ✅ **Backward Compatible** - Nenhuma breaking change
- 🛠️ **5 Novos Helpers** - Para reutilização de código
- 🔒 **Sistema de Segurança** - Middleware e isolamento opcional
- 📊 **Monitoramento** - Health checks e métricas
- 🐛 **Correções Críticas** - POST routes agora funcionam 100%

## 🚀 Migração Rápida

### **Passo 1: Atualizar Dependência**

```bash
# Atualizar para a versão estável
composer require pivotphp/reactphp:^0.1.0
```

### **Passo 2: Verificar Funcionalidade (Opcional)**

```bash
# Executar testes para verificar se tudo funciona
composer test

# Validar qualidade de código
composer quality:check
```

### **Passo 3: Aproveitar Novos Recursos (Opcional)**

```php
// Usar novos helpers para melhor performance
use PivotPHP\ReactPHP\Helpers\JsonHelper;
use PivotPHP\ReactPHP\Helpers\ResponseHelper;

// Middleware de segurança opcional
$app->use(\PivotPHP\ReactPHP\Middleware\SecurityMiddleware::class);
```

## 📋 Compatibilidade

### **✅ 100% Compatível**

Todas essas funcionalidades continuam funcionando exatamente igual:

```php
// ✅ Service Provider registration
$app->register(ReactPHPServiceProvider::class);

// ✅ Todas as rotas existentes
$app->get('/', function($req, $res) {
    return $res->json(['message' => 'Works!']);
});

$app->post('/data', function($req, $res) {
    // ✅ AGORA FUNCIONA MELHOR - POST routes corrigidas!
    $data = $req->body;
    return $res->json(['received' => $data]);
});

// ✅ Comando console
php bin/console serve:reactphp --host=0.0.0.0 --port=8080

// ✅ Configurações existentes
return [
    'server' => [
        'debug' => false,
        'streaming' => false,
        'max_concurrent_requests' => 100,
    ],
];
```

### **🔧 Melhorias Automáticas**

Você ganha automaticamente:

- **POST/PUT/PATCH requests** agora funcionam 100%
- **Melhor performance** com helpers internos
- **Maior estabilidade** com 180 testes automatizados (contagem atual)
- **Monitoramento básico** de memória e performance
- **Headers de segurança** automáticos

## 🛠️ Novos Recursos Opcionais

### **1. Sistema de Helpers**

```php
// Antes (ainda funciona)
$data = json_decode($request->body, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    return $response->status(400)->json(['error' => 'Invalid JSON']);
}

// ✨ Novo - Mais robusto
use PivotPHP\ReactPHP\Helpers\JsonHelper;
use PivotPHP\ReactPHP\Helpers\ResponseHelper;

$data = JsonHelper::decode($request->body);
if (!$data) {
    return ResponseHelper::createErrorResponse(400, 'Invalid JSON');
}
```

### **2. Middleware de Segurança (Opcional)**

```php
// Adicionar isolamento entre requisições
$app->use(\PivotPHP\ReactPHP\Middleware\SecurityMiddleware::class);

// Ou configurar manualmente
$app->use(function($request, $response, $next) {
    // Middleware customizado
    return $next($request, $response);
});
```

### **3. Monitoramento de Saúde (Opcional)**

```php
use PivotPHP\ReactPHP\Monitoring\HealthMonitor;

// Endpoint de health check
$app->get('/health', function($request, $response) {
    $monitor = new HealthMonitor($loop, $logger); // LoopInterface, LoggerInterface obrigatorios
    return $response->json($monitor->getHealthStatus());
});

// Métricas básicas
$app->get('/metrics', function($request, $response) {
    return $response->json([
        'memory_usage' => memory_get_usage(true),
        'peak_memory' => memory_get_peak_usage(true),
        'uptime_seconds' => time() - $_SERVER['REQUEST_TIME'],
    ]);
});
```

### **4. Identificação de Clientes (Opcional)**

```php
use PivotPHP\ReactPHP\Helpers\RequestHelper;

$app->post('/api/secure', function($request, $response) {
    // Detectar IP real (com proxies)
    $clientIp = RequestHelper::getClientIp($request, $trustProxies = true);

    // Identificador único do cliente
    $clientId = RequestHelper::getClientIdentifier($request);

    // Usar em logs ou rate limiting
    error_log("Request from client: {$clientId} (IP: {$clientIp})");

    return $response->json(['client_id' => $clientId]);
});
```

## 🔧 Configurações Adicionais (Opcional)

### **Segurança Avançada**

`config/reactphp.php` não tem (e nunca teve) chaves `security`/`monitoring` — isolamento
de requisição, guarda de memória e detecção de código bloqueante são classes que você
instancia diretamente em código, não flags de config nem env vars:

```php
use PivotPHP\ReactPHP\Security\{RequestIsolation, MemoryGuard, BlockingCodeDetector};

$isolation = new RequestIsolation();
$guard = new MemoryGuard($loop); // LoopInterface obrigatório
$guard->startMonitoring();

$detector = new BlockingCodeDetector();
// use scanFile()/scanCode() — não existe analyzeCode() nem startRuntimeDetection()
```

As únicas chaves reais de `config/reactphp.php` são `server`, `middleware`, `loop`,
`performance` — veja [README.md](../README.md#-configuração) para a lista completa e
as env vars que cada uma realmente lê (`REACTPHP_STREAMING`,
`REACTPHP_MAX_CONCURRENT_REQUESTS`, `REACTPHP_REQUEST_BODY_SIZE_LIMIT`, etc.).
`REACTPHP_ENABLE_MONITORING`, `REACTPHP_HEALTH_CHECKS`, `REACTPHP_REQUEST_ISOLATION`,
`REACTPHP_MEMORY_GUARD`, `REACTPHP_MEMORY_WARNING`, `REACTPHP_MEMORY_CRITICAL` e
`REACTPHP_BLOCKING_DETECTION` não são lidas por nenhum código deste pacote.

## 🧪 Validação da Migração

### **Teste Básico de Funcionalidade**

```bash
# 1. Atualizar para v0.1.0
composer require pivotphp/reactphp:^0.1.0

# 2. Executar testes (se você tem)
composer test

# 3. Verificar qualidade de código
composer phpstan
composer cs:check

# 4. Iniciar servidor de teste
php bin/console serve:reactphp --host=localhost --port=8080
```

### **Teste de POST Routes (Agora Funcionam!)**

```bash
# Testar POST request com JSON
curl -X POST http://localhost:8080/api/data \
  -H "Content-Type: application/json" \
  -d '{"name": "Test", "value": 123}'

# Deve retornar:
# {"received": {"name": "Test", "value": 123}, "processed": true}
```

### **Teste de Health Check (Novo)**

```php
// Adicionar rota de health check temporária
$app->get('/health-test', function($req, $res) {
    return $res->json([
        'status' => 'healthy',
        'version' => '0.1.0',
        'memory' => memory_get_usage(true),
        'timestamp' => date('c')
    ]);
});
```

```bash
# Testar health check
curl http://localhost:8080/health-test
```

## 🚨 Possíveis Problemas

### **1. POST Routes Não Funcionavam na v0.0.x**

**Problema**: Se você tinha POST routes que retornavam erro 500.

**✅ Solução**: Automática na v0.1.0! Os POST routes agora funcionam perfeitamente.

```php
// Isso agora funciona 100%
$app->post('/api/data', function($request, $response) {
    $data = $request->body; // JSON automaticamente parseado
    return $response->json(['received' => $data]);
});
```

### **2. Problemas de Memória**

**Problema**: Vazamentos de memória em long-running processes.

**✅ Solução**: Usar o novo MemoryGuard (opcional):

```php
// Monitoramento automático de memória
$app->use(\PivotPHP\ReactPHP\Middleware\SecurityMiddleware::class);
```

### **3. Headers de Segurança**

**Problema**: Faltavam headers de segurança básicos.

**✅ Solução**: Headers automáticos com a v0.1.0:

```php
use PivotPHP\ReactPHP\Helpers\HeaderHelper;

// Headers de segurança automáticos
$app->use(function($request, $response, $next) {
    $result = $next($request, $response);

    // Adicionar headers de segurança
    $securityHeaders = HeaderHelper::getSecurityHeaders($isProduction = true);
    foreach ($securityHeaders as $name => $value) {
        $result = $result->withHeader($name, $value);
    }

    return $result;
});
```

### **4. Debug de Requisições**

**Problema**: Difícil debugar problemas de requisições.

**✅ Solução**: Usar helpers de debug:

```php
use PivotPHP\ReactPHP\Helpers\RequestHelper;

$app->use(function($request, $response, $next) {
    // Log detalhado de requisições
    $clientIp = RequestHelper::getClientIp($request, true);
    $clientId = RequestHelper::getClientIdentifier($request);

    error_log(sprintf(
        'Request: %s %s from %s (%s)',
        $request->getMethod(),
        $request->getUri()->getPath(),
        $clientIp,
        $clientId
    ));

    return $next($request, $response);
});
```

## 📊 Benefícios da Migração

### **Antes (v0.0.x)**
- ❌ POST routes com problemas
- ❌ Código duplicado
- ❌ Sem monitoramento
- ❌ Isolation manual
- ❌ Headers básicos

### **Depois (v0.1.0)**
- ✅ POST routes 100% funcionais
- ✅ 5 helpers especializados
- ✅ Monitoramento integrado
- ✅ Isolamento automático
- ✅ Headers de segurança
- ✅ 180 testes automatizados (contagem atual)
- ✅ PHPStan Level 9
- ✅ PSR-12 compliance

## 🎯 Próximos Passos

Após migrar para v0.1.0:

1. **✅ Validar** que tudo funciona igual ou melhor
2. **🛠️ Explorar** novos helpers para otimizar código
3. **🔒 Considerar** middleware de segurança para produção
4. **📊 Adicionar** endpoints de health check e métricas
5. **🚀 Preparar** para v0.2.0 com WebSockets e HTTP/2

## 💡 Dicas de Migração

### **Migração Gradual**

```php
// 1. Primeiro - atualizar versão
composer require pivotphp/reactphp:^0.1.0

// 2. Testar funcionalidade existente
// (tudo deve funcionar igual)

// 3. Gradualmente adicionar novos recursos
use PivotPHP\ReactPHP\Helpers\JsonHelper;

// Substituir json_decode/encode por helpers mais robustos
$data = JsonHelper::decode($input); // ao invés de json_decode($input, true)
```

### **Performance Testing**

```bash
# Comparar performance antes/depois
ab -n 1000 -c 10 http://localhost:8080/

# Monitorar memória
watch -n 1 'ps aux | grep serve:reactphp'
```

### **Logs de Migração**

```php
// Adicionar logs para monitorar migração
error_log("PivotPHP ReactPHP v0.1.0 - Server started successfully");

$app->use(function($request, $response, $next) {
    $start = microtime(true);
    $result = $next($request, $response);
    $duration = microtime(true) - $start;

    error_log(sprintf(
        'v0.1.0 - %s %s - %dms - %dMB',
        $request->getMethod(),
        $request->getUri()->getPath(),
        round($duration * 1000),
        round(memory_get_usage(true) / 1024 / 1024)
    ));

    return $result;
});
```

---

## 📞 Suporte

Se encontrar problemas na migração:

1. **📖 Documentação**: [Technical Overview](TECHNICAL-OVERVIEW.md)
2. **🐛 Issues**: [GitHub Issues](https://github.com/PivotPHP/pivotphp-reactphp/issues)
3. **📧 Discussions**: https://github.com/PivotPHP/pivotphp-reactphp/discussions

**🎉 Bem-vindo à v0.1.0 - A primeira release estável do PivotPHP ReactPHP!**
