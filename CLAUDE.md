# O² Rocket — contexto para o Claude

## O que é este repositório

- `o2-rocket/` — **o produto**: plugin de performance para WordPress (namespace `O2Rocket`, PHP 7.4+, WP 6.3+, textos em português). Todo o trabalho acontece aqui.
- `wp-rocket/` — WP Rocket 3.20.3 (GPL-2.0-or-later), **só para comparação**. Estude e adapte o código, mas nunca traga nada que dependa dos servidores, da API ou da licença do WP Rocket, nem o nome/marca "WP Rocket". (`wp-rocket/licence-data.php` foi apagado: tinha a chave de licença. A chave continua no histórico do git — commits `668a998`/`e685bf1` —; troque a chave na conta do WP Rocket ou reescreva o histórico se o repositório ficar público.)
- `o2-rocket-config/` (raiz) — configuração gerada num WAMP local. **Não usar**: o plugin gera a dele em `wp-content/o2-rocket-config/`.
- `dist/o2-rocket.zip` — pacote instalável, gerado ao fim de cada fase (`zip` da pasta `o2-rocket/`).
- `COMPARATIVO.md` — todas as funções do WP Rocket e o status de cada uma no O² Rocket (criado na Fase 5).

**Objetivo:** o O² Rocket ter todas as funções do WP Rocket, ou fazer melhor.

## Regras (não negociáveis)

- O plugin funciona em qualquer WordPress só copiando `o2-rocket/` para `wp-content/plugins/`:
  - sem caminhos, domínios ou configurações fixas no código;
  - todas as bibliotecas dentro de `o2-rocket/` (com a licença delas), sem composer nem arquivos fora da pasta;
  - Apache, LiteSpeed e Nginx (sem .htaccess no Nginx, sem quebrar), Windows/WAMP e Linux, sites em subpasta e multisite;
  - se não conseguir gravar algum arquivo (wp-config, .htaccess, cache), avisa no painel em vez de quebrar;
  - desinstalação limpa (`uninstall.php` remove opções, drop-in, linha do wp-config, regras do .htaccess e cache).
- Padrão do código: namespace `O2Rocket`, PHP 7.4+, WP 6.3+, textos em português, comentários curtos em português (uma linha, só quando explicam o "porquê"), configurações registradas no schema de `admin/Settings.php` (opção `o2_rocket_settings`), `final class`, propriedades tipadas.
- Rodar `php -l` em tudo que for alterado e testar num WordPress com SQLite (tema padrão e Elementor).

## Arquitetura atual (resumo)

- `o2-rocket.php` — constantes, requisitos, autoloader PSR-4 (`O2Rocket\Admin\` → `admin/`, `O2Rocket\` → `includes/`), helpers globais (`direct_filesystem()`, `atomic_write()`, `prepare_cache_directory()`, `run_for_each_site()`), ativação/desativação, `bootstrap()` no `plugins_loaded`.
- `admin/Admin_UI.php` — menu, abas, Settings API renderizada como cards, CSS/JS inline. Schema via filtro `o2_rocket_settings_schema`; campos via `o2_rocket_register_settings`. Abas custom via `o2_rocket_admin_tab_{slug}`.
- `admin/Settings.php` — schema + campos de todas as abas (toggle, textarea, ...).
- `includes/Cache/Page_Cache.php` — buffer de saída que grava `wp-content/cache/o2-rocket/pages/{host}/{caminho}/index[-https].html`, purga por URL/tudo, exclusões.
- `includes/Cache/Drop_In_Manager.php` — instala `drop-ins/advanced-cache.php` em `wp-content/`, liga `WP_CACHE` no wp-config, grava `wp-content/o2-rocket-config/{host}.php`.
- `drop-ins/advanced-cache.php` — serve o HTML antes do WordPress carregar. **A normalização de host/caminho/query precisa ser idêntica à do `Page_Cache`.**
- `includes/Optimization/File_Optimizer.php` — remove `?ver=`, defer de JS.
- `includes/Media/*` — lazy load, WebP (GD), otimização em massa.
- `admin/Dashboard.php`, `admin/Media_Dashboard.php` — painéis.

## Ambiente de teste (sessão do Claude)

Scripts em `$SCRATCHPAD/scripts/` (não versionados): `mksite.sh <nome> <porta> [subdir]` cria WP (clone de WordPress/WordPress) + SQLite (WordPress/sqlite-database-integration); `serve.sh <nome> <porta>` sobe `php -S` com 4 workers e roteador estilo Nginx (try_files, sem .htaccess); `sync.sh` copia o plugin; `wpe` executa PHP no contexto do WP. wp-cli não está disponível (download bloqueado). Não há Apache/Nginx no contêiner: regras de .htaccess são validadas por geração/sintaxe, não por servidor real.

## Decisões tomadas (dúvidas resolvidas pela opção mais segura)

- Bootstrap do painel deixa de vir do CDN jsDelivr: arquivos locais em `assets/vendor/bootstrap/` (MIT, com LICENSE), conferidos pelo hash SRI original.
- Parâmetros de marketing (Fase 1.1): a lista vem de `wp-rocket/dynamic-lists.json` (`cache_ignored_parameters`), sem o parâmetro de marca do WP Rocket. Requisição só com esses parâmetros **serve** o cache existente da URL limpa, mas **nunca gera** cache. Qualquer outro parâmetro pula o cache (exceto os da lista "Parâmetros com cache próprio", que geram um arquivo separado).
- Gzip (Fase 1.2): cópia `index[...].html.gz` (nome compatível com `gzip_static` do Nginx). O drop-in só serve o `.gz` com `Content-Encoding: gzip` se o navegador aceitar e se `zlib.output_compression`/`ob_gzhandler` não estiverem ativos (evita compressão dupla). Sempre envia `Vary: Accept-Encoding`.
- Servir o cache direto pelo mod_rewrite (sem PHP), como o WP Rocket faz no Apache: **não** entra na Fase 1 (o drop-in já é rápido e as regras precisariam replicar todas as exclusões). Reavaliar na Fase 5.

## Roadmap

Fase 1 — Cache
1. Servir o cache para URLs que só têm parâmetros de marketing; não gerar cache a partir delas.
2. Gzip pré-comprimido (`.gz`), servido com `Content-Encoding` quando aceito; incluído na limpeza.
3. "Limpar cache" na barra do admin (tudo e página atual); campo de tempo de vida (antes fixo em 10 h); cron que apaga páginas expiradas.
4. Pré-carregamento: fila pelo sitemap (WordPress, Yoast, Rank Math) em lotes pelo WP-Cron; recarrega URLs limpas. Prefetch ao passar o mouse com speculation rules (WP 6.8+) e fallback em JS.
5. Regras de .htaccess (GZIP e cache do navegador) com marcadores, removidas ao desativar.

Fase 2 — Minificar CSS e JS; atrasar JS até a interação (exclusões de `wp-rocket/dynamic-lists-delayjs.json`); remover emojis e jQuery Migrate.

Fase 3 — Lazy load no HTML inteiro (cabeçalho/rodapé do Elementor, fundos CSS); dimensões de imagem faltando; preload/fetchpriority da imagem LCP com beacon local; YouTube com miniatura; WebP com perda para JPEG.

Fase 4 — Google Fonts local; preload de fontes; preconnect; limpeza do banco com agendamento; Heartbeat; importar/exportar configurações.

Fase 5 — CSS não utilizado e CSS crítico gerados no próprio servidor; CDN; Cloudflare/Varnish; compatibilidade com hospedagens e plugins (`wp-rocket/inc/ThirdParty`). Criar `COMPARATIVO.md`.

## Status

- [ ] Fase 1
- [ ] Fase 2
- [ ] Fase 3
- [ ] Fase 4
- [ ] Fase 5
- [ ] COMPARATIVO.md

Ao terminar cada fase: `php -l`, testes no WP+SQLite, commit, push, atualizar este arquivo e gerar `dist/o2-rocket.zip`.
