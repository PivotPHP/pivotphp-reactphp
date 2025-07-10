# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2025-01-10

### 🎉 Primeira Release Estável

Esta é a primeira release estável da extensão, com arquitetura robusta, qualidade de código excepcional e 100% dos testes passando.

### Added

#### **Sistema de Helpers Especializados**
- **HeaderHelper** - Centralização de processamento de headers HTTP com conversão PSR-7 e headers de segurança
- **ResponseHelper** - Criação padronizada de respostas de erro com IDs únicos e formatação consistente  
- **JsonHelper** - Operações JSON type-safe com fallbacks automáticos e validação integrada
- **GlobalStateHelper** - Backup/restore seguro de superglobals com isolamento entre requisições
- **RequestHelper** - Identificação de clientes e análise de requisições com suporte a proxies

#### **Sistema de Segurança Avançado**
- **SecurityMiddleware** - Middleware de segurança com isolamento automático de requisições
- **RequestIsolation** - Interface e implementação para isolamento completo de contexto de requisições
- **MemoryGuard** - Monitoramento contínuo de memória com alertas e limpeza automática
- **BlockingCodeDetector** - Detecção estática e runtime de código que pode bloquear o event loop
- **GlobalStateSandbox** - Sandbox seguro para manipulação de variáveis globais

#### **Sistema de Monitoramento**
- **HealthMonitor** - Monitoramento de saúde da aplicação com métricas em tempo real
- Sistema de alertas para problemas críticos de performance e memória
- Detecção automática de vazamentos de memória e recursos

#### **Testes e Qualidade**
- 113 testes automatizados com 319 assertions (100% passando)
- Helpers de teste especializados (AssertionHelper, MockHelper, OutputBufferHelper)
- Testes de integração completos para cenários reais
- Testes de segurança para todos os componentes de proteção
- Testes de performance e stress para validação de carga

### Changed

#### **RequestBridge Aprimorado**
- Implementação de stream rewinding automático para leitura correta do body
- Parsing automático de JSON com detecção de Content-Type
- Suporte completo a application/x-www-form-urlencoded
- Preservação adequada de headers customizados e atributos PSR-7

#### **ReactServer Otimizado**
- Gerenciamento robusto de estado global para compatibilidade total com PivotPHP
- Implementação de backup/restore automático de superglobals ($_POST, $_SERVER)
- Uso de factory method seguro `createFromGlobals()` para criação de Request
- Suporte completo a POST/PUT/PATCH com bodies JSON complexos

#### **Integração PivotPHP Core 1.1.0**
- Sintaxe de rotas corrigida para padrão PivotPHP (`:id` ao invés de `{id}`)
- Integração com test mode do PivotPHP Core para controle de output
- Uso adequado dos métodos de container (`getContainer()`, `make()`)
- Compatibilidade total com sistema de hooks e eventos do Core

#### **Controle de Output Melhorado**
- Buffer management automático durante execução de testes
- Integração com constante PHPUNIT_TESTSUITE do PivotPHP Core
- Supressão inteligente de output inesperado sem afetar funcionalidade
- Método `withoutOutput()` para execução silenciosa de código

### Fixed

#### **Correções Críticas**
- **POST Route Status 500** - Resolvido problema de incompatibilidade entre ReactPHP e parsing de body do PivotPHP
- **Stream Positioning** - Correção de rewinding de streams para leitura correta de conteúdo
- **Global State Isolation** - Implementação adequada de isolamento entre requisições
- **Memory Leaks** - Eliminação de vazamentos de memória em long-running processes

#### **Problemas de Qualidade**
- **PHPStan Level 9** - Resolução de todos os 388 erros de análise estática
- **PSR-12 Compliance** - Correção de todas as violações de padrão de codificação
- **Test Timeouts** - Correção de timeouts em ReactServerTest com inicialização adequada
- **Output Buffer Issues** - Resolução de problemas de buffer em ambiente de testes

#### **Refatorações**
- Extração de 95+ linhas de código duplicado através do sistema de helpers
- Separação de classes múltiplas por arquivo para melhor manutenibilidade
- Criação de interfaces para classes final para permitir mocking em testes
- Padronização de error responses em todo o código

### Security

#### **Melhorias de Segurança**
- Isolamento completo de estado entre requisições concorrentes
- Detecção automática de código potencialmente bloqueante
- Monitoramento de memória com alertas para prevenção de ataques DoS
- Headers de segurança automáticos (X-Frame-Options, X-Content-Type-Options, etc.)
- Sanitização adequada de logs para prevenir exposição de dados sensíveis

#### **Validação e Sanitização**
- Validação rigorosa de entrada em todos os helpers
- Sanitização automática de dados sensíveis em logs
- Proteção contra manipulação maliciosa de superglobals
- Isolamento de contexto para prevenir vazamento de dados entre requisições

### Performance

#### **Otimizações**
- Eliminação de código duplicado resultando em menor footprint de memória
- Lazy loading adequado de componentes PSR-7
- Cache inteligente de configurações e objetos reutilizáveis
- Redução de overhead através de helpers especializados

#### **Monitoramento**
- Métricas detalhadas de performance por requisição
- Alertas automáticos para degradação de performance
- Detecção de gargalos em tempo real
- Análise de uso de memória contínua

### Documentation

#### **Documentação Técnica Completa**
- Guia de implementação detalhado com exemplos práticos
- Diretrizes de segurança para ambientes de produção
- Guia de testes e QA com melhores práticas
- Análise de performance com benchmarks
- Guia de troubleshooting com soluções comuns

#### **Exemplos Atualizados**
- Exemplos básicos com sintaxe correta do PivotPHP
- Recursos avançados incluindo streaming e async processing
- Configurações de produção recomendadas
- Integração com sistemas de monitoramento

### Testing

#### **Cobertura Completa**
- Bridge components (Request/Response conversion)
- Server lifecycle e handling de requisições
- Todos os helpers e utilities
- Componentes de segurança e isolamento
- Cenários de integração real
- Error handling e recovery

#### **Qualidade dos Testes**
- Uso de mocks adequados com interfaces extraídas
- Testes de unidade focados e isolados
- Testes de integração abrangentes
- Validação de edge cases e error conditions
- Performance testing para cenários de carga

## [0.0.2] - 2025-01-09

### Added
- Full compatibility with PivotPHP Core 1.1.0
- Support for high-performance mode features from PivotPHP 1.1.0
- Advanced features example (`examples/advanced-features.php`) demonstrating:
  - Server-Sent Events (SSE) streaming
  - File streaming with chunked transfer
  - Long polling for real-time updates
  - Async batch processing
  - Hooks system integration
- Streaming response detection based on headers and content type
- Improved error handling with support for custom error handlers
- Middleware aliases support for ReactPHP-specific middleware
- Better integration with PivotPHP's container system

### Changed
- Updated `RequestBridge` to use native PSR-7 support from PivotPHP Core 1.1.0
- Updated `ResponseBridge` to work directly with PSR-7 responses without compatibility layer
- Improved `ReactServer` with better Application integration and streaming support
- Updated `ReactPHPServiceProvider` to use new PivotPHP Core 1.1.0 APIs
- Updated all examples to use new Application namespace (`PivotPHP\Core\Core\Application`)
- Changed service provider registration to use class name instead of instance
- Updated container access methods to use `getContainer()`, `getConfig()`, and `make()`

### Removed
- Removed obsolete `Psr7CompatibilityAdapter` (no longer needed with PivotPHP Core 1.1.0's native PSR-7 support)

### Fixed
- Fixed namespace issues with PivotPHP Core classes
- Fixed ServiceProvider constructor requirements
- Fixed middleware registration to use `$app->use()` method
- Resolved all code style issues for PSR-12 compliance

### Dependencies
- Updated minimum PivotPHP Core requirement to 1.1.0

## [0.0.1] - 2025-01-09

### Added
- Initial release of PivotPHP ReactPHP Extension
- ReactPHP server integration with PivotPHP framework
- PSR-7 request/response bridge between ReactPHP and PivotPHP
- Service provider for easy integration
- Console command `serve:reactphp` for starting the server
- Support for async operations and promises
- Configuration file support
- Basic examples (server.php and async-example.php)
- Full test coverage for core components
- PHPStan Level 9 static analysis
- PSR-12 code style compliance

### Features
- Continuous runtime to keep application in memory between requests
- Event-driven architecture with non-blocking I/O
- High performance by eliminating bootstrap overhead
- Full PSR-7 compatibility with PivotPHP's implementation
- Middleware pipeline support
- Graceful server shutdown with signal handling (SIGTERM, SIGINT)

### Dependencies
- Requires PHP 8.1 or higher
- Compatible with PivotPHP Core 1.0+
- ReactPHP HTTP Server 1.9+
- PSR-7 Message Interface 1.x (ReactPHP requirement)

[0.0.1]: https://github.com/PivotPHP/pivotphp-reactphp/releases/tag/v0.0.1