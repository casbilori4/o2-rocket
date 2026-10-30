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

- `o2-rocket.php` — constantes, requisitos, autoloader PSR-4 (`O2Rocket\Admin\` → `admin/`, `O2Rocket\` → `includes/`), helpers globais (`direct_filesystem()`, `atomic_write()`, `prepare_cache_directory()`, `run_for_each_site()`, `unschedule_cron_events()`), ativação/desativação, `bootstrap()` no `plugins_loaded`. Ordem dos hooks de `update_option_o2_rocket_settings`: cache (10) → pré-carregamento (20) → .htaccess (30).
- `uninstall.php` + `includes/Core/Uninstaller.php` — desinstalação limpa (todas as opções/transients/tabelas/crons/post meta de todos os sites, drop-in, `WP_CACHE`, bloco do .htaccess, `wp-content/cache/o2-rocket/`, `wp-content/o2-rocket-config/`). Arquivos `.webp` ao lado das imagens são mantidos (outros plugins usam o mesmo nome). Novos dados persistentes precisam entrar aqui.
- `admin/Admin_UI.php` — menu, abas, Settings API renderizada como cards, CSS/JS inline (inclui o polling do pré-carregamento). Schema via filtro `o2_rocket_settings_schema`; campos via `o2_rocket_register_settings`. Abas custom via `o2_rocket_admin_tab_{slug}`.
- `admin/Settings.php` — schema + campos de todas as abas. Renderizadores: `render_toggle`, `render_textarea`, `render_lifespan`, `render_select`, `render_number`. Campo sem valor salvo usa o `default` do schema.
- **Cache de página**
  - `includes/Cache/Cache_Engine.php` — PHP puro (sem funções do WP), usado pelo drop-in **e** pelo `Page_Cache`: normalização de host/caminho/query, escolha do arquivo (`index[-mobile][-https][-q{hash}].html` + `.gz`/`.br`), exclusões por cookie/User-Agent/regex, regra da barra final, escolha da configuração por site e entrega (304, gzip/brotli, `Vary`). Qualquer regra nova de "servir ou não" vai aqui.
  - `drop-ins/advanced-cache.php` — modelo do drop-in; o `Drop_In_Manager` troca `'{{O2_ROCKET_ENGINE}}'` pelo caminho do `Cache_Engine.php` ao instalar (sem o plugin, o drop-in não faz nada).
  - `includes/Cache/Drop_In_Manager.php` — instala o drop-in, `WP_CACHE` no wp-config, grava `wp-content/o2-rocket-config/{host}.php` (site na raiz) ou `{host}-{md5(home_path):12}.php` (subpasta/multisite). Desligar num site remove só a configuração dele; drop-in e `WP_CACHE` saem quando não sobra nenhuma configuração.
  - `includes/Cache/Page_Cache.php` — gera o cache (buffer em `template_redirect` 9999), opções (tempo de vida, celular, gzip, cookies/UA/parâmetros, "sempre limpar"), limpeza por evento (post, termo, usuário, comentário, menus, widgets, tema, plugins, atualizações, opções do site, permalinks, home), cron `o2_rocket_purge_expired`. Ações: `o2_rocket_after_purge_all`, `o2_rocket_after_purge_urls` (URLs realmente apagadas), `o2_rocket_after_purge_expired`, `o2_rocket_cache_saved`, `o2_rocket_manual_purge_all` (botão "Limpar todo o cache": módulos de minificação etc. devem limpar seus arquivos aqui).
  - `includes/Cache/Cache_Controls.php` — barra do admin (tudo / esta página / este conteúdo / pré-carregar), ação "Limpar cache" na lista de posts, `admin-post.php?action=o2_rocket_purge&scope=...`, avisos.
- **Pré-carregamento** (`includes/Preload/`)
  - `Preload_Queue` — tabela `{prefix}o2_rocket_preload` por site (url, url_hash único, status, attempts, modified).
  - `Sitemap_Parser` — detecta sitemaps (Yoast, Rank Math, SEOPress, AIOSEO, The SEO Framework, núcleo) e lê XML por regex (sem SimpleXML).
  - `Preloader` — cron `o2_rocket_preload` a cada minuto enquanto houver fila; 20 s por execução, uma requisição bloqueante por vez (sem rajadas), trava por transient; sem sitemap usa posts/termos do banco; aprende URLs visitadas; a tela do painel adianta a fila via AJAX.
  - `Link_Prefetch` + `assets/js/prefetch.js` — speculation rules do núcleo (WP 6.8+) em `prefetch`/`moderate` com exclusões; fallback em JS.
- `includes/Server/Htaccess.php` — bloco `# BEGIN O2 Rocket` no topo do .htaccess (Brotli preferido via `SetEnvIfNoCase ... no-gzip`, GZIP, mod_expires, mod_headers). Só Apache/LiteSpeed, só o site principal no multisite; erro de escrita vira aviso com o bloco para copiar; Nginx recebe trecho equivalente no painel.
- **Otimização do HTML** (`includes/Optimization/`)
  - `Html_Pipeline` — um único buffer (`template_redirect` 10000, depois do Page_Cache) que aplica o filtro `o2_rocket_optimize_html` por etapas: 10 minificação, 20 CSS (Fase 5), 30 fontes, 35 dimensões, 40 lazy load, 60 WebP, 70 atraso de JS, 80 dicas de recurso, 90 CDN. Pula feeds, embeds, AMP, `?o2r-nooptimize`, `DONOTMINIFY`/`DONOTOPTIMIZE` e respostas não HTML. `Html_Pipeline::is_visitor()` = não logado (etapas que mexem na execução só valem para visitantes).
  - `Html_Tag` — ler/editar atributos de tags respeitando aspas (use sempre ele, não regex solta).
  - `Css_Url_Rewriter` — `url()`/`@import` relativos → absolutos; `font-display: swap`.
  - `Asset_Minifier` — CSS/JS locais → `wp-content/cache/o2-rocket/min/{blog_id}/{nome}-{hash}.{css|js}` (hash do caminho+mtime+tamanho+versão). Pula `.min.js`, módulos ES, `integrity`, externos, exclusões. Cópias apagadas em `o2_rocket_after_purge_all`/limpeza manual.
  - `Delay_Js` + `assets/js/delay-js.js` (em linha no `<head>`) — `<script>` vira `type="o2rocket/delayed"` (`data-o2r-src`, `data-o2r-type`); o carregador roda na primeira interação, em ordem (normais → defer; async em paralelo), emula `readyState`/`DOMContentLoaded`/`load`/`pageshow`, adia o `ready` do jQuery, troca `document.write` e repete o clique. Exclusões: `data/delay-js-exclusions.php` (padrões + 262 plugins + 153 temas + 21 serviços, das listas do WP Rocket), aplicadas automaticamente para plugins/tema ativos; serviços marcados; modo seguro; padrões do usuário (regex ou texto). `assets/js/elementor-animations.js` mostra as animações de entrada do Elementor visíveis sem esperar o JS.
  - `File_Optimizer` — remove `?ver=`, defer de JS (via WP_Scripts, com exclusões), remove emojis e jQuery Migrate (só front-end).
- **Fontes e dicas de recurso**
  - `Optimization\Google_Fonts` (etapa 30) — `<link>` e `@import` do Google Fonts → CSS e fontes em `wp-content/cache/o2-rocket/fonts/{blog}/google/` (UA moderno para woff2; falha volta ao Google e só tenta de novo após 1 h); `display=swap`; remove preconnect/dns-prefetch do Google quando localizado. A opção `fonts_display_swap` também decide o `font-display` do CSS minificado (filtro `o2_rocket_font_display_swap`). Fontes só saem na limpeza manual.
  - `Media\Resource_Hints` (etapa 85) — preconnect (+dns-prefetch) e preload de fontes, manuais e automáticos (o beacon manda `resources: {fonts, origins, cors}` = pedidos antes da LCP + 1 s; coluna `extras` da tabela de LCP, DB v2). Insere logo depois do `<meta charset>`.
- **Banco e Heartbeat**
  - `Database\Cleaner` — revisões (manter N mais recentes), rascunhos automáticos, lixeira, spam, transients expirados ou todos, metadados órfãos, otimizar tabelas (MySQL: `SHOW TABLE STATUS` + `OPTIMIZE TABLE`, InnoDB só com >10 MB livres; SQLite: `VACUUM` pelo PDO do plugin de integração). Lotes de 200 com orçamento de tempo; AJAX "Limpar agora" repete até `done`; cron `o2_rocket_database_cleanup` diário/semanal/mensal às 03:15 do site, continua em 1 min se não acabar.
  - `Optimization\Heartbeat` — site/painel/editor: padrão, reduzir (120 s) ou desativar (deregister do script).
- `admin/Tools.php` — aba Ferramentas: exportar JSON (sem chaves marcadas `secret` no schema), importar (só chaves do schema via `Admin_UI::build_settings()`, mantém segredos atuais), restaurar padrões, ações rápidas, diagnóstico com texto para suporte.
- `includes/Core/Url_Resolver.php` — URL do próprio site ↔ arquivo no disco (wp-content/plugins/uploads fora do padrão, WP em subpasta; nunca sai das pastas do WP).
- `vendor/` — matthiasmullie/minify e path-converter (MIT) com namespace `O2Rocket\Vendor\...` (ver `vendor/README.md`); registrado no autoloader do `o2-rocket.php`.
- `assets/js/*.min.js` — gerados a partir dos `.js` com a própria biblioteca; a primeira linha é `/*o2r:{md5 do .js}*/` e `script_path()`/`script_url()`/`inline_script()` só usam o `.min.js` se o hash bater (senão caem no `.js`; também com `SCRIPT_DEBUG`). Ao editar um `.js`, gerar o `.min.js` de novo (script `min-assets.php` do ambiente de teste: `'/*o2r:' . md5_file( $js ) . "*/\n" . ( new O2Rocket\Vendor\Minify\JS( $codigo ) )->minify()`).
- **Mídia** (`includes/Media/`)
  - `Lazy_Load` (etapa 40) — HTML inteiro a partir do `<body>`: `<img>`, `<picture>` (sources esperam junto, `<noscript>` com o picture inteiro), iframes, fundos em `style=""` (`var(--o2r-bg-N,none)` + `data-o2r-bg`), miniatura no lugar do YouTube (`data-o2r-iframe`). Imediatas: imagens acima da dobra medidas (`o2_rocket_lazyload_eager_urls`) ou, sem medição, as 2 primeiras + o primeiro fundo; `fetchpriority=high`/`loading=eager`; classes/atributos de exclusão; padrões do usuário. `Lazy_Load::normalize_url()` compara caminhos ignorando host, query e `.webp`.
  - `Css_Background_Lazy` (etapa 38) — fundos em `<style>` e em arquivos CSS locais (inclusive a cópia minificada): `url()` de `background`/`background-image` → `var(--o2r-bg-{md5(url):10},none)`; cópia `min/{blog}/{nome}-bg-{hash}.css` + mapa `.json`; `window.o2rLazyBg=[{s:seletor,v:{var:url}}]` antes do `</body>`; fundos acima da dobra viram `:root{--o2r-bg-x:url()}` no `<head>`. Ignora `:hover`/`:focus`, pseudo-elementos observam o elemento.
  - `Image_Dimensions` (etapa 35) — width/height de imagens e SVGs locais (`Url_Resolver`), completando pela proporção quando só uma medida existe.
  - `Lcp_Optimizer` + `Lcp_Store` + `assets/js/beacon.js` (etapa 80) — beacon em linha mede LCP e acima da dobra; `admin-ajax.php?action=o2_rocket_beacon` (sem nonce, assinado com HMAC da URL+modo, 1 gravação por URL/dispositivo, limite de taxa, imagem precisa estar no HTML em cache). Tabela `{prefix}o2_rocket_lcp`. Com medição: `<link rel=preload as=image fetchpriority=high>` logo após o `<title>` (só se todos os dispositivos concordam), `fetchpriority=high` e sem `loading=lazy` no `<img>` LCP, acima da dobra fora do lazy load. Modo do dispositivo: `mobile`/`desktop` com cache separado para celular, senão `auto` (mede os dois). Medições apagadas em `o2_rocket_after_purge_all` e para as URLs limpas (exceto a limpeza disparada pelo próprio beacon).
  - `Image_Optimizer` — WebP: JPEG com perda (qualidade configurável, editor do WP, GD ou Imagick), PNG sem perda (GD do PHP 8.1+, senão com perda q≥90); meta `_o2_rocket_webp_quality`; mudar a qualidade devolve os JPEGs à fila; troca de URLs no HTML (etapa 60) e nas cópias minificadas de CSS (`o2_rocket_minified_css`).
  - `Bulk_Optimizer`, `WebP_Editor_GD`.
- `admin/Dashboard.php`, `admin/Media_Dashboard.php` — painéis.

## Ambiente de teste (sessão do Claude)

Scripts em `$SCRATCHPAD/scripts/` (não versionados): `mksite.sh <nome> <porta> [subdir]` cria WP (clone de WordPress/WordPress, versão 7.2-beta) + SQLite (WordPress/sqlite-database-integration); `serve.sh <nome> <porta>` sobe `php -S` com 4 workers e roteador estilo Nginx (try_files, sem .htaccess); `sync.sh` copia o plugin; `wpe`/`wpa`/`wpm` executam PHP no contexto do WP; `login.sh` + `savetab.py` salvam abas do painel como o navegador; `shot.js` tira captura com Playwright/Chromium. wp-cli não está disponível (download bloqueado). Apache 2.4 + mod_php foi instalado no contêiner (apt) com sites em `/srv/o2test/` (o usuário www-data não atravessa a pasta temporária): `apache` (porta 8090), `multi` (multisite em subpastas, 8091), `sub/mtrix.com.br` (site em subpasta, 8092). Elementor 4.5.0 entra só como código PHP clonado do GitHub (os assets JS/CSS precisam de build com npm, que foi bloqueado pela política da sessão).

## Decisões tomadas (dúvidas resolvidas pela opção mais segura)

- Bootstrap do painel deixa de vir do CDN jsDelivr: arquivos locais em `assets/vendor/bootstrap/` (MIT, com LICENSE), conferidos pelo hash SRI original.
- Parâmetros de marketing (Fase 1.1): a lista vem de `wp-rocket/dynamic-lists.json` (`cache_ignored_parameters`), sem o parâmetro de marca do WP Rocket. Requisição só com esses parâmetros **serve** o cache existente da URL limpa, mas **nunca gera** cache. Qualquer outro parâmetro pula o cache (exceto os da lista "Parâmetros com cache próprio", que geram um arquivo separado).
- Gzip (Fase 1.2): cópia `index[...].html.gz` (nome compatível com `gzip_static` do Nginx). O drop-in só serve o `.gz` com `Content-Encoding: gzip` se o navegador aceitar e se `zlib.output_compression`/`ob_gzhandler` não estiverem ativos (evita compressão dupla). Sempre envia `Vary: Accept-Encoding`.
- Servir o cache direto pelo mod_rewrite (sem PHP), como o WP Rocket faz no Apache: **não** entra na Fase 1 (o drop-in já é rápido e as regras precisariam replicar todas as exclusões). Reavaliar na Fase 5.

- Motor de cache compartilhado (`Cache_Engine`): o drop-in inclui o arquivo do plugin em vez de duplicar a lógica. Se a pasta do plugin sumir, o drop-in simplesmente não serve cache.
- Configuração do drop-in por site: `{host}.php` (raiz) e `{host}-{hash}.php` (subpasta/multisite em subpastas); o drop-in escolhe pelo maior prefixo do caminho. Corrige multisite em subpastas, que antes sobrescrevia a configuração.
- Cache separado para celular: opção, desligada por padrão (temas responsivos não precisam). Detecção idêntica ao `wp_is_mobile()` do núcleo.
- Cópia `.br` só quando o PHP tem a extensão brotli; senão `.gz`. No Apache, as regras preferem Brotli para conteúdo dinâmico/estático.
- Pré-carregamento usa requisições bloqueantes em série com orçamento de tempo (o WP Rocket dispara requisições não bloqueantes em rajada): mais gentil com o servidor e permite saber o resultado (redirecionamento/404 saem da fila). Desligado por padrão.
- Regras de .htaccess ligadas por padrão (como no WP Rocket), sempre dentro de `<IfModule>`. Servir o cache direto pelo mod_rewrite fica para a Fase 5.
- Desinstalação não apaga os `.webp` (outros plugins usam o mesmo nome de arquivo).
- `dist/o2-rocket.zip` exclui `assets/images/source/` (logo original de 1,1 MB, não usado em tempo de execução).
- Fase 2: otimizações (minificação, atraso de JS, lazy load, WebP) rodam para todos no front-end, mas o atraso de JS só para visitantes não logados (como o WP Rocket, que não otimiza para logados). `.min.js` não é minificado de novo (filtro `o2_rocket_minify_min_js`).
- Combinar JS/CSS não foi implementado de propósito: com HTTP/2/3 é contraproducente e o próprio WP Rocket desliga a combinação de JS quando o atraso de JS está ativo (e removeu a de CSS).
- Exclusões conhecidas do atraso de JS ficam ligadas por padrão para os plugins/tema ativos (o WP Rocket pede para marcar cada um): opção mais segura.
- Padrões de exclusão inválidos como regex (há alguns nas listas originais) viram texto literal.
- Fase 3: lazy load continua em JavaScript (IntersectionObserver, margem de 300 px) com `<noscript>`, como já era o plugin; sem medição de LCP, as 2 primeiras imagens e o primeiro fundo da página carregam de imediato.
- Beacon de LCP sem nonce (páginas em cache duram mais que um nonce): a proteção é HMAC da URL + uma gravação por URL/dispositivo + limite de 120 gravações a cada 10 min + conferência da imagem no HTML em cache.
- Preload da LCP só quando celular e computador concordam (sem cache separado para celular) — senão um dos dois baixaria uma imagem que não usa; o `fetchpriority` vai nos dois.
- AVIF não foi implementado: exigiria variante de cache por cabeçalho Accept ou `<picture>`; WebP já é aceito por todos os navegadores atuais.
- Fase 4: limpeza do banco tem "transients expirados" (seguro) separado de "todos os transients" (o que o WP Rocket faz), mantém N revisões por post e limpa metadados órfãos. Nada é marcado por padrão.
- Heartbeat desligado por padrão (quando ligado: site desativado, painel e editor reduzidos).
- Combinar várias URLs do Google Fonts numa só não foi feito: com as fontes hospedadas localmente não há o que combinar.
- Push para o GitHub falhou com 403 (o app do Claude não tem acesso de escrita ao repositório). Os commits ficam locais; tentar `git push -u origin claude/epic-hypatia-j4qzlg` de novo ao fim de cada fase.

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

- [x] **Fase 1 — Cache** (concluída)
  - [x] Parâmetros de marketing (73 da lista + extras do usuário) servem o cache da URL limpa e nunca geram cache; parâmetros com cache próprio (opção); parâmetro desconhecido pula o cache.
  - [x] `.gz` (e `.br` com a extensão) gravados e servidos com `Content-Encoding`, `Content-Length` e `Vary`; respeita `q=0`; sem compressão dupla (testado no Apache real).
  - [x] Barra do admin (limpar tudo/página/conteúdo, pré-carregar), ação na lista de posts, tempo de vida configurável (minutos/horas/dias, 0 = nunca), cron de expirados que re-enfileira no pré-carregamento.
  - [x] Pré-carregamento por sitemap (núcleo, Yoast, Rank Math, SEOPress, AIOSEO, TSF + sitemaps extras), fallback pelo banco, exclusões, celular; prefetch ao passar o mouse (speculation rules + fallback JS).
  - [x] .htaccess (Brotli/GZIP, cache do navegador) com marcadores, removido ao desativar/desinstalar; trecho para Nginx no painel.
  - [x] Também: cookies e User-Agents que impedem cache, "sempre limpar estas URLs", limpeza em termos/usuários/widgets/opções/atualizações/home, multisite em subpastas isolado, `uninstall.php`, Bootstrap local.
- [x] **Fase 2 — Arquivos** (concluída)
  - [x] Minificar CSS (URLs relativas corrigidas, `font-display: swap`) e JS (pula `.min.js` e módulos), com exclusões; cópias com hash no nome.
  - [x] Atrasar JS até a interação com carregador próprio, exclusões automáticas por plugin/tema ativo, serviços externos, modo seguro, tempo opcional, exclusões do usuário; correção das animações do Elementor. Testado no Chromium (ordem, eventos, jQuery ready, document.write, clique repetido).
  - [x] Remover emojis e jQuery Migrate; exclusões do defer; pipeline único de HTML (WebP migrado para ele).
  - Não feito de propósito: combinar JS/CSS (ver Decisões).
- [x] **Fase 3 — Mídia** (concluída)
  - [x] Lazy load no HTML inteiro (cabeçalho/rodapé/Elementor, `<picture>`, iframes, fundos em `style`) e fundos em CSS (arquivos e `<style>`, testado com o CSS de seções/colunas do Elementor).
  - [x] Dimensões faltando (imagens e SVGs locais).
  - [x] Beacon local de LCP/acima da dobra → preload + `fetchpriority` + exclusão do lazy load; testado no Chromium (gravou `bg-img` do herói e a página seguinte saiu com preload e `:root` do fundo).
  - [x] Miniatura do YouTube com play/autoplay.
  - [x] WebP com perda para JPEG (qualidade configurável, reprocessamento automático) e WebP também no CSS minificado.
- [x] **Fase 4 — Fontes, banco e ferramentas** (concluída)
  - [x] Google Fonts local (`<link>` e `@import`), `display=swap`, preload de fontes (manual + automático pelo beacon), preconnect (manual + automático).
  - [x] Limpeza do banco (9 itens, manter N revisões, órfãos, VACUUM no SQLite) com botão AJAX e agendamento.
  - [x] Heartbeat (site/painel/editor) em aba própria.
  - [x] Ferramentas: exportar/importar/restaurar e diagnóstico.
- [ ] **Fase 5 — em andamento**
  - [x] Entrega do CSS gerada no servidor (`Optimization\Css_Parser`, `Css_Usage`, `Css_Delivery`, `assets/js/css-loader.js`): modo "unused" (CSS usado em linha + `<template>` com o CSS completo carregado na primeira interação) e "critical" (tokens acima da dobra do beacon, ou estimativa pelo início do `<body>`, ou CSS manual; folhas com `media=print` + onload). Árvore de cada arquivo em cache como PHP (`min/{blog}/tree-{hash}.php`). Testado: 0 pixel de diferença (tema padrão e Elementor), e o crítico sozinho desenha a tela inicial idêntica.
  - [ ] CDN; Cloudflare; Varnish; Sucuri; compatibilidade com hospedagens e plugins (`wp-rocket/inc/ThirdParty`); extras (mod_rewrite, cache para logados); `COMPARATIVO.md`.

Pendências conhecidas (reavaliar na Fase 5): cache para usuários logados (add-on do WP Rocket), servir o cache pelo mod_rewrite, limpeza do cache do host antigo após troca de domínio.

Ao terminar cada fase: `php -l`, testes no WP+SQLite (php -S e Apache), commit, push, atualizar este arquivo e gerar o zip:

```bash
cd /home/user/o2-rocket && rm -f dist/o2-rocket.zip && mkdir -p dist && zip -rq dist/o2-rocket.zip o2-rocket -x 'o2-rocket/assets/images/source/*' -x '*.DS_Store'
```
