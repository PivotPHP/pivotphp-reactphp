# PivotPHP ReactPHP - Análise de Desempenho e Limitações

## Sumário Executivo

O PivotPHP ReactPHP é uma extensão que transforma o PivotPHP em um servidor de alta performance com runtime contínuo, utilizando o event loop do ReactPHP. Esta análise detalha o desempenho, benefícios e limitações desta abordagem.

## Análise de Desempenho

### 1. Vantagens de Performance

#### 1.1 Eliminação do Overhead de Bootstrap
- **Tradicional (PHP-FPM)**: ~20-50ms por requisição para carregar framework
- **ReactPHP**: Bootstrap único na inicialização
- **Ganho**: 100% de redução no overhead após primeira requisição

#### 1.2 Reutilização de Conexões
```php
// ReactPHP: Conexão persistente
static $db = null;
if (!$db) {
    $db = new PDO(...); // Conecta apenas uma vez
}

// PHP-FPM: Nova conexão a cada request (sem pool)
$db = new PDO(...); // Conecta toda vez
```
- **Ganho**: 10-100ms por requisição economizados

#### 1.3 Cache em Memória
- **Dados em memória**: Acesso instantâneo (microsegundos)
- **Redis/Memcached**: 1-5ms de latência de rede
- **Ganho**: 10-100x mais rápido para dados frequentes

#### 1.4 Concorrência Não-Bloqueante
- **ReactPHP**: Pode processar outras requisições durante I/O
- **PHP-FPM**: Thread/processo bloqueado durante I/O
- **Ganho**: 2-10x mais requisições simultâneas com mesmos recursos

### 2. Benchmarks Observados

#### 2.1 Requisições por Segundo (RPS)
```
Endpoint simples (JSON):
- PHP-FPM: ~1,000-3,000 RPS
- ReactPHP: ~5,000-15,000 RPS
- Ganho: 3-5x

Endpoint com I/O (Database):
- PHP-FPM: ~200-500 RPS
- ReactPHP: ~1,000-3,000 RPS
- Ganho: 3-6x
```

#### 2.2 Latência
```
P50 (mediana):
- PHP-FPM: 15-30ms
- ReactPHP: 2-5ms

P99 (99º percentil):
- PHP-FPM: 100-200ms
- ReactPHP: 20-50ms
```

#### 2.3 Uso de Memória
```
Por processo:
- PHP-FPM: 20-50MB por worker
- ReactPHP: 50-200MB total (compartilhado)

Para 100 workers:
- PHP-FPM: 2-5GB
- ReactPHP: 50-200MB
```

### 3. Cenários Ideais

1. **APIs de Alta Frequência**: Milhares de requisições pequenas
2. **WebSockets/SSE**: Conexões persistentes de longa duração
3. **Microserviços**: Baixa latência entre serviços
4. **Real-time**: Chat, notificações, dashboards ao vivo
5. **Cache Layer**: Substituir Redis para dados hot

## Limitações Técnicas

### 1. Limitações de Código

#### 1.1 Variáveis Globais e Estado
```php
// PROBLEMA: Estado global persiste entre requests
$_SESSION['user'] = 'João';
// Próxima requisição pode ver dados de João!

// SOLUÇÃO: Usar request attributes
$request = $request->withAttribute('user', 'João');
```

#### 1.2 Funções Bloqueantes
```php
// PROBLEMA: Bloqueia todo o servidor
sleep(5); // ❌ NUNCA usar
file_get_contents('http://api.com'); // ❌ Bloqueante

// SOLUÇÃO: Usar alternativas assíncronas
$loop->addTimer(5, function() { ... }); // ✅
$browser->get('http://api.com')->then(...); // ✅
```

#### 1.3 Memory Leaks
```php
// PROBLEMA: Acumula memória
class Service {
    static $cache = []; // Cresce infinitamente
    
    public function process($data) {
        self::$cache[] = $data; // Vazamento!
    }
}

// SOLUÇÃO: Limitar e limpar caches
class Service {
    static $cache = [];
    const MAX_CACHE = 1000;
    
    public function process($data) {
        self::$cache[] = $data;
        if (count(self::$cache) > self::MAX_CACHE) {
            self::$cache = array_slice(self::$cache, -500);
        }
    }
}
```

### 2. Limitações de Bibliotecas

#### 2.1 Incompatibilidades Comuns
- **PDO**: Use pool de conexões ou react/mysql
- **Curl**: Use react/http-client
- **Sessions nativas**: Use implementação customizada
- **File uploads grandes**: Podem consumir muita memória

#### 2.2 Extensões PHP Problemáticas
```
Incompatíveis:
- pcntl_fork() - Quebra o event loop
- exit()/die() - Mata o servidor inteiro
- set_time_limit() - Não funciona como esperado

Cuidado especial:
- ob_* functions - Podem interferir com streaming
- header() - Use Response objects
- $_GLOBALS - Estado compartilhado perigoso
```

### 3. Limitações Operacionais

#### 3.1 Monitoramento
- **APM tradicional** pode não funcionar (New Relic, etc)
- **Profilers** precisam suportar long-running processes
- **Logs** devem ser assíncronos para não bloquear

#### 3.2 Deploy e Atualizações
```bash
# Problema: Como atualizar sem downtime?

# Solução 1: Blue-Green deployment
- Servidor A (porta 8080) rodando v1
- Inicia Servidor B (porta 8081) com v2
- Atualiza load balancer para B
- Para servidor A

# Solução 2: Graceful reload
- Servidor recebe SIGUSR1
- Para de aceitar novas conexões
- Finaliza conexões existentes
- Reinicia com novo código
```

#### 3.3 Debugging
- **Xdebug**: Performance péssima, usar apenas em dev
- **var_dump()**: Saída vai para console, não browser
- **Breakpoints**: Param todo o servidor

### 4. Limitações de Escala

#### 4.1 CPU-Bound
```php
// Single-threaded por natureza
// CPU intensivo bloqueia outras requests

// PROBLEMA:
for ($i = 0; $i < 1000000; $i++) {
    $result = complex_calculation($i);
}

// SOLUÇÃO: Usar workers externos
$process = new Process(['php', 'worker.php', $data]);
$process->start($loop);
```

#### 4.2 Limites de Memória
```
Fatores de crescimento:
- Conexões WebSocket: ~10-50KB por conexão
- Cache interno: Cresce sem limites se não gerenciado
- Objetos não liberados: Acumulam over time

Recomendações:
- Máximo 10k conexões simultâneas por processo
- Restart periódico (diário/semanal)
- Monitoramento constante de memória
```

## Estratégias de Mitigação

### 1. Arquitetura Híbrida
```
                    Nginx
                      |
        +-------------+-------------+
        |                           |
   ReactPHP Server              PHP-FPM
   (APIs, WebSocket)         (Admin, Upload)
```

### 2. Circuit Breakers
```php
// Protege contra falhas em cascata
$breaker = new CircuitBreaker();
$breaker->call(function() {
    return $api->request();
})->onError(function() {
    return $cache->get();
});
```

### 3. Rate Limiting
```php
// Previne abuse e sobrecarga
$limiter = new RateLimiter(100, 60); // 100 req/min
if (!$limiter->allow($clientId)) {
    return Response::json(['error' => 'Too many requests'], 429);
}
```

### 4. Health Checks
```php
$router->get('/health', function() {
    $checks = [
        'memory' => memory_get_usage() < 100 * 1024 * 1024,
        'connections' => $activeConnections < 1000,
        'response_time' => $avgResponseTime < 100,
    ];
    
    $healthy = !in_array(false, $checks);
    
    return Response::json([
        'status' => $healthy ? 'healthy' : 'unhealthy',
        'checks' => $checks,
    ], $healthy ? 200 : 503);
});
```

## Recomendações

### Quando Usar ReactPHP

✅ **Ideal para:**
- APIs REST de alta performance
- Aplicações real-time (chat, notificações)
- Microserviços com baixa latência
- Proxies e gateways
- Serviços com muito I/O e pouco CPU

❌ **Evitar para:**
- Aplicações com processamento CPU-intensivo
- Sites tradicionais com muito conteúdo HTML
- Aplicações que dependem de muitas bibliotecas síncronas
- Projetos com equipe sem experiência em programação assíncrona

### Best Practices

1. **Sempre use timeouts** em operações externas
2. **Limite tamanhos** de caches e buffers
3. **Monitore memória** constantemente
4. **Implemente graceful shutdown**
5. **Use supervisor** para auto-restart
6. **Faça load testing** antes de produção
7. **Tenha circuit breakers** para dependências
8. **Documente** comportamentos assíncronos

## Conclusão

O PivotPHP ReactPHP oferece ganhos significativos de performance (3-10x) para cenários específicos, mas requer cuidados especiais com gerenciamento de estado, memória e compatibilidade. É uma excelente escolha para APIs e serviços real-time, mas pode não ser adequado para todas as aplicações.

A decisão de usar deve considerar:
- Perfil de carga da aplicação
- Experiência da equipe
- Requisitos de latência
- Complexidade vs benefício

Com as práticas corretas, é possível construir serviços extremamente eficientes e escaláveis.