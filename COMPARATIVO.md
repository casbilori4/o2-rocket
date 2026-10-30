# Comparativo: WP Rocket 3.20.3 × O² Rocket

Todas as funções do WP Rocket (tela de configurações `inc/Engine/Admin/Settings/Page.php`, módulos de `inc/Engine/`, add-ons e compatibilidade de `inc/ThirdParty/` e `inc/3rd-party/`) e como cada uma está no O² Rocket.

**Legenda**

| Símbolo | Significado |
| --- | --- |
| ✅ | Implementado, com o mesmo efeito |
| ⭐ | Implementado e melhor (o que muda está na observação) |
| 🟡 | Implementado em parte |
| ❌ | Não implementado, de propósito (o motivo está na observação) |
| ➖ | Não se aplica: depende dos servidores, da API ou da licença do WP Rocket |

Resumo (seções 1 a 12): das 133 funções listadas, **113 implementadas** (35 delas melhores que no WP Rocket), 5 em parte, 7 não implementadas de propósito e 8 que só existem como serviço ou conta do WP Rocket. A seção 13 lista os requisitos de ambiente.

---

## 1. Cache de página

| Função do WP Rocket | O² Rocket | Status | Observação |
| --- | --- | :---: | --- |
| Cache de página em HTML estático (drop-in `advanced-cache.php` + `WP_CACHE`) | Aba Page Cache → "Habilitar Page Caching" | ⭐ | O drop-in inclui o motor do plugin (`Cache_Engine`) em vez de copiar a lógica; configuração por site em `wp-content/o2-rocket-config/` (multisite em subpastas isolado); autocorreção se o drop-in sumir; aviso no painel se o wp-config não for gravável. |
| Cache separado para celular (`cache_mobile` + `do_caching_mobile_files`) | "Cache separado para celulares" | ✅ | Detecção igual à `wp_is_mobile()`. Desligado por padrão (temas responsivos não precisam). |
| Cache separado para HTTPS (`cache_ssl`) | Sempre ativo | ✅ | Arquivo `index-https.html`. |
| Cópia comprimida do cache (`.html_gzip`) | "Páginas pré-comprimidas (GZIP)" | ⭐ | `index.html.gz` (compatível com `gzip_static` do Nginx) e `.br` quando o PHP tem Brotli; respeita `q=0` e não comprime duas vezes (`zlib.output_compression`). |
| Resposta 304 (Last-Modified / If-Modified-Since) | Automático | ✅ | Pelo drop-in e, com a entrega pelo servidor, pelo próprio Apache (ETag também). |
| Entregar o cache pelo mod_rewrite (regras no .htaccess) | "Entregar o cache pelo servidor" | ⭐ | Opcional. Mesmas exclusões do drop-in, host literal, regra da barra final, cabeçalhos de cache e de diagnóstico, gzip; cai no PHP em qualquer dúvida. Só site único (o WP Rocket também não faz no multisite). |
| Tempo de vida do cache (`purge_cron_interval`) | "Tempo de vida do cache" (minutos/horas/dias, 0 = nunca) | ✅ | |
| Cron que apaga páginas expiradas | `o2_rocket_purge_expired` | ⭐ | Em lotes curtos (retoma na próxima execução) e devolve as URLs ao pré-carregamento. |
| Nunca fazer cache destas URLs (`cache_reject_uri`) | "URIs Excluídas" | ✅ | Regex ou caminho. |
| Cookies que impedem o cache (`cache_reject_cookies`) | "Cookies que impedem o cache" | ✅ | Login, senha de post, autor de comentário e WooCommerce já vêm incluídos. |
| User-Agents que nunca recebem cache (`cache_reject_ua`) | "Navegadores e robôs sem cache" | ✅ | |
| Parâmetros com cache próprio (`cache_query_strings`) | "Parâmetros com cache próprio" | ✅ | Arquivo `-q{hash}` separado. |
| Parâmetros de marketing ignorados (`cache_ignored_parameters`) | Lista de 73 parâmetros + "Parâmetros ignorados (extras)" | ⭐ | Servem o cache da URL limpa, mas nunca geram cache (evita cache envenenado por um parâmetro desconhecido). |
| Sempre limpar estas URLs (`cache_purge_pages`) | "Sempre limpar estas URLs" | ✅ | |
| Limpeza automática ao publicar/editar/excluir post, comentário, termo, usuário, menu, widget, tema, plugins, atualizações, permalinks | `Page_Cache` | ⭐ | Inclui ancestrais, termos, autor, arquivos por data, posts vizinhos, página de posts, arquivo do tipo, opções do site e a home; só a home + paginação quando é a home. |
| Cache para usuários logados (add-on `cache_logged_user`) | — | ❌ | O drop-in roda antes do WordPress e não consegue validar o cookie de login; servir HTML de um usuário para outro por cookie forjado seria vazamento de dados. Opção mais segura. |
| Cookies dinâmicos (`rocket_cache_dynamic_cookies`: uma cópia por valor de cookie) | — | ❌ | Usado por seletores de moeda/geolocalização. Com esses plugins, adicione o cookie em "Cookies que impedem o cache". |
| Cookies obrigatórios (`rocket_cache_mandatory_cookies`) | — | ➖ | Só existe para os add-ons acima. |
| Limpeza do cache após troca de domínio (`DomainChange`) | Automático | ✅ | Apaga o cache e a configuração do drop-in do endereço antigo e avisa no painel. |
| Opções por post: "Nunca fazer cache desta página" (metabox) | Caixa "O² Rocket" no editor | ⭐ | Gravado no post: não precisa limpar o cache do site inteiro como o WP Rocket (que altera a lista global de exclusões). |
| Feeds, REST, AJAX, 404, buscas, POST, páginas com senha fora do cache | Automático | ✅ | |
| Cabeçalho de diagnóstico | `X-O2-Rocket-Cache: HIT/MISS/HIT (servidor)` | ✅ | |

## 2. Pré-carregamento

| Função do WP Rocket | O² Rocket | Status | Observação |
| --- | --- | :---: | --- |
| Pré-carregar o cache (`manual_preload`) | "Pré-carregar o cache" | ⭐ | Requisições bloqueantes em série com orçamento de tempo (o WP Rocket dispara rajadas não bloqueantes): mais gentil com hospedagens pequenas e sabe o resultado (redirecionamento/404 saem da fila). Progresso ao vivo no painel. |
| Sitemaps (`sitemap_preload`, SEO plugins) | Núcleo, Yoast, Rank Math, SEOPress, AIOSEO, The SEO Framework, Jetpack + "Sitemaps adicionais" | ✅ | Sem sitemap: posts e termos do banco. |
| Aprender URLs visitadas | Automático | ✅ | |
| Excluir URLs do pré-carregamento (`preload_excluded_uri`) | "URLs fora do pré-carregamento" | ✅ | |
| Pré-carregar de novo o que foi limpo | Automático | ✅ | Inclui as páginas expiradas. |
| Versão para celular no pré-carregamento | Automático com cache separado para celular | ✅ | |
| Pré-carregar links ao passar o mouse (`preload_links`) | "Pré-carregar links" | ✅ | Speculation rules do núcleo (WP 6.8+) com exclusões; fallback em JS. |

## 3. Arquivos (CSS e JavaScript)

| Função do WP Rocket | O² Rocket | Status | Observação |
| --- | --- | :---: | --- |
| Minificar CSS (`minify_css`) | "Minificar CSS" | ✅ | URLs relativas corrigidas, `font-display: swap`; cópia só se ficar menor. |
| Excluir CSS da minificação (`exclude_css`) | "CSS que não deve ser minificado" | ✅ | |
| Minificar JS (`minify_js`) | "Minificar JavaScript" | ✅ | Pula `.min.js`, módulos ES, `integrity` e externos. |
| Excluir JS (`exclude_js`) | "JavaScript que não deve ser minificado" | ✅ | |
| Combinar JS (`minify_concatenate_js`) e excluir JS em linha (`exclude_inline_js`) | — | ❌ | Com HTTP/2 e HTTP/3 a combinação piora o cache e o paralelismo; o próprio WP Rocket desliga a combinação com o atraso de JS (e já removeu a de CSS). |
| Remover CSS não utilizado (`remove_unused_css`, serviço externo do WP Rocket) | "Otimizar a entrega do CSS" → Remover CSS não utilizado | ⭐ | Gerado no próprio servidor (parser de CSS próprio), sem enviar páginas para fora e sem fila de espera; CSS completo carregado na primeira interação. Testado: 0 pixel de diferença (tema padrão e Elementor). |
| Lista de seletores sempre mantidos (`remove_unused_css_safelist`) | "Seletores que nunca saem" | ✅ | |
| Carregar CSS de forma assíncrona + CSS crítico (`async_css` / `critical_css`, serviço externo) | "Otimizar a entrega do CSS" → CSS crítico | ⭐ | Crítico gerado no servidor a partir dos elementos que o beacon viu acima da dobra (ou estimativa, ou "CSS crítico de reserva"); folhas com `media=print` + onload. |
| Regenerar CSS crítico / Limpar CSS usado | "Limpar todo o cache" | ✅ | As árvores de CSS são refeitas sob demanda. |
| Adiar JavaScript (`defer_all_js`) + exclusões (`exclude_defer_js`) | "Carregar JavaScript de forma adiada (Defer)" + "JavaScript que não deve ser adiado" | ✅ | Pelo `WP_Scripts` (scripts inline dependentes continuam corretos). |
| Atrasar JavaScript até a interação (`delay_js`) | "Atrasar JavaScript" | ⭐ | Carregador próprio: ordem preservada, emula `DOMContentLoaded`/`load`/`readyState`, adia o `ready` do jQuery, troca `document.write`, repete o primeiro clique; tempo limite opcional ("Executar sem interação depois de"). Animações de entrada do Elementor visíveis sem esperar o JS. |
| Exclusões com um clique por plugin/tema/serviço (`delay_js_exclusions_selected`, listas dinâmicas baixadas) | "Exclusões conhecidas" + "Serviços externos que não esperam" | ⭐ | As listas (262 plugins, 153 temas, 21 serviços) vão dentro do plugin e valem sozinhas para o que está ativo, sem baixar nada. |
| Exclusões manuais do atraso (`delay_js_exclusions`) | "Scripts que não devem esperar" | ✅ | Regex ou texto (regex inválida vira texto). |
| Modo seguro do atraso (`delay_js_execution_safe_mode`) | "Modo seguro" | ✅ | |
| Remover emojis (`emoji`) | "Desativar emojis do WordPress" | ✅ | |
| Remover jQuery Migrate | "Remover jQuery Migrate" | ⭐ | O WP Rocket não tem. |
| Remover `?ver=` dos arquivos | "Remover Query Strings" | ✅ | |
| Otimizar Google Fonts / combinar (`minify_google_fonts`) | Google Fonts local | ⭐ | Com as fontes no próprio servidor não há o que combinar. |
| Minificar HTML | — | ➖ | Removido do próprio WP Rocket nas versões 3.x (ganho mínimo com gzip/brotli). |
| Desligar otimizações por página (metabox) | Caixa "O² Rocket" no editor | ✅ | Todas ou cada uma: minificação, CSS, defer, atraso, lazy load, LCP, renderização sob demanda, CDN. |
| Constantes `DONOTROCKETOPTIMIZE` / `DONOTMINIFY` | `DONOTOPTIMIZE`, `DONOTMINIFY`, `DONOTCACHEPAGE`, `?o2r-nooptimize` | ✅ | |

## 4. Mídia

| Função do WP Rocket | O² Rocket | Status | Observação |
| --- | --- | :---: | --- |
| Lazy load de imagens (`lazyload`) | "Ativar Lazy Load para Imagens" | ⭐ | Página inteira (cabeçalho, rodapé, widgets, Elementor), `<picture>` com `<noscript>` completo; acima da dobra medida carrega na hora. |
| Lazy load de iframes e vídeos (`lazyload_iframes`) | "Ativar Lazy Load para Iframes/Vídeos" | ✅ | |
| Miniatura no lugar do YouTube (`lazyload_youtube`) | "Miniatura no lugar do YouTube" | ✅ | |
| Lazy load de imagens de fundo do CSS (`lazyload_css_bg_img`) | "Lazy Load para imagens de fundo" | ⭐ | Fundos em `style=""`, `<style>` e arquivos CSS locais (inclusive a cópia minificada); fundos acima da dobra viram `:root` no `<head>`. |
| Exclusões do lazy load (`exclude_lazyload`) | "Nunca aplicar lazy load em" | ✅ | |
| Dimensões das imagens (`image_dimensions`) | "Dimensões das imagens" | ⭐ | Também SVGs; completa pela proporção quando só uma medida existe. |
| Otimizar imagens críticas / LCP (Above the Fold) | "Otimizar a imagem LCP" | ⭐ | Beacon próprio assinado com HMAC e com limite de taxa (a imagem precisa estar no HTML em cache); preload só quando celular e computador concordam. |
| Renderização sob demanda automática (Lazy Render Content) | "Renderização sob demanda" | ⭐ | Alcança o conteúdo dos temas de blocos (5 níveis), testa a contenção de verdade no navegador, pula blocos com fixo/sticky ou desenho para fora e grava a altura em `contain-intrinsic-size` (a barra de rolagem não pula). |
| Cache de WebP (`cache_webp`, depende do Imagify ou similar) | "Converter novas imagens" + "Exibir WebP no site" (+ conversão em massa) | ⭐ | Converte sozinho (JPEG com perda configurável, PNG sem perda), em massa ou no upload, e troca também no CSS minificado. |
| Integração com o Imagify | — | ➖ | Serviço do mesmo fabricante; substituído pela conversão própria. |

## 5. Fontes e dicas de recurso

| Função do WP Rocket | O² Rocket | Status | Observação |
| --- | --- | :---: | --- |
| Hospedar o Google Fonts localmente (`host_fonts_locally`) | "Hospedar o Google Fonts no site" | ✅ | `<link>` e `@import`; se o download falhar, volta para o Google e tenta de novo depois de 1 h. |
| `font-display: swap` | "Texto visível durante o carregamento" | ✅ | Google Fonts e CSS minificado. |
| Preload automático de fontes (`auto_preload_fonts`) | "Pré-carregar fontes automaticamente" | ✅ | Pelo beacon (fontes pedidas antes da LCP). |
| Preload manual de fontes | "Fontes para pré-carregar" | ✅ | |
| Preconnect automático de domínios externos | "Preconnect automático" | ✅ | |
| DNS prefetch (`dns_prefetch`) | "Domínios para preconnect" | ⭐ | Gera preconnect + dns-prefetch. |

## 6. Banco de dados

| Função do WP Rocket | O² Rocket | Status | Observação |
| --- | --- | :---: | --- |
| Revisões (`database_revisions`) | "Revisões" + "Revisões a manter por post" | ⭐ | Pode manter as N mais recentes de cada post. |
| Rascunhos automáticos, posts na lixeira | Itens da aba Banco de Dados | ✅ | |
| Comentários spam e na lixeira | Itens da aba Banco de Dados | ✅ | |
| Transients (`database_all_transients`) | "Transients expirados" e "Todos os transients" | ⭐ | A opção segura (só expirados) existe à parte. |
| Otimizar tabelas (`database_optimize_tables`) | "Otimizar tabelas" | ⭐ | InnoDB só quando há espaço a recuperar; SQLite com `VACUUM`. |
| Metadados órfãos | "Metadados órfãos" | ⭐ | O WP Rocket não tem. |
| Limpeza agendada (`schedule_automatic_cleanup` + frequência) | "Limpeza automática" + "Frequência" (diária/semanal/mensal às 03:15) | ✅ | Em lotes, continua sozinha se não terminar. |
| Limpar agora | Botão com progresso | ✅ | |

## 7. CDN

| Função do WP Rocket | O² Rocket | Status | Observação |
| --- | --- | :---: | --- |
| Ativar CDN e endereços CNAME (`cdn`, `cdn_cnames`) | Aba CDN | ✅ | |
| Zonas por tipo de arquivo (`cdn_zone`) | "URL zona" por linha (images, css, js, fonts, media) | ✅ | Vários endereços do mesmo tipo dividem os arquivos, sempre o mesmo arquivo no mesmo endereço. |
| Arquivos fora da CDN (`cdn_reject_files`) | "Arquivos fora da CDN" | ✅ | |
| URLs da CDN dentro do CSS minificado | Automático | ✅ | |
| Preconnect para a CDN | Automático | ✅ | |
| CORS das fontes servidas pela CDN | Regra no .htaccess / trecho do Nginx | ⭐ | Os módulos ES ficam no domínio do site (sem CORS na CDN eles não carregariam). |
| RocketCDN | — | ➖ | CDN paga do WP Rocket. |

## 8. Heartbeat

| Função do WP Rocket | O² Rocket | Status | Observação |
| --- | --- | :---: | --- |
| Controlar a Heartbeat (`control_heartbeat`) | Aba Heartbeat → "Controlar a Heartbeat" | ✅ | |
| Comportamento no site, no painel e no editor (reduzir/desativar) | "No site", "No painel", "No editor" | ✅ | |

## 9. Add-ons e integrações

| Função do WP Rocket | O² Rocket | Status | Observação |
| --- | --- | :---: | --- |
| Varnish (`varnish_auto_purge`) | Aba Integrações → Varnish | ✅ | PURGE com regex na limpeza total; IP opcional; ligado sozinho nas hospedagens com Varnish. |
| Cloudflare: credenciais (`cloudflare_api_key`, `cloudflare_email`, `cloudflare_zone_id`) | Token de API + ID da zona | ⭐ | Token com permissão só de limpar cache (o WP Rocket aceita a chave global, que dá acesso à conta inteira). O token não vai na exportação. |
| Cloudflare: limpar o cache junto | Automático | ⭐ | Tudo na limpeza total; só as URLs alteradas (em lotes de 30) nas edições. |
| Cloudflare: modo de desenvolvimento (`cloudflare_devmode`) | Botões ligar/desligar | ✅ | |
| Cloudflare: testar conexão | Botão | ✅ | |
| Cloudflare: configurações ideais automáticas (`cloudflare_auto_settings`) | — | ❌ | Mudaria a configuração da zona do cliente (Rocket Loader, minificação, cache do navegador) sem ele ver; preferimos não alterar nada na conta. |
| Cloudflare: reescrita de protocolo para SSL "Flexible" (`cloudflare_protocol_rewrite`) | — | ❌ | Remendo para o modo Flexible, que é inseguro; o certo é usar "Full". |
| Sucuri: limpar o cache do firewall (`sucury_waf_cache_sync`, `sucury_waf_api_key`) | Aba Integrações → Sucuri | ✅ | Tudo ou por URL. A chave não vai na exportação. |
| Nginx Helper e Varnish HTTP Purge | Automático | ✅ | |

## 10. Ferramentas e painel

| Função do WP Rocket | O² Rocket | Status | Observação |
| --- | --- | :---: | --- |
| Exportar configurações | Aba Ferramentas | ⭐ | Sem tokens e chaves de API. |
| Importar configurações | Aba Ferramentas | ⭐ | Só chaves conhecidas, cada uma validada; mantém os segredos atuais. |
| Restaurar padrões | Aba Ferramentas | ✅ | |
| Voltar para a versão anterior (rollback) | — | ➖ | Baixa a versão dos servidores do WP Rocket. |
| Barra do admin: limpar tudo, limpar esta página, pré-carregar | Menu "O² Rocket" na barra do admin | ⭐ | Também "limpar este conteúdo e as páginas relacionadas" ao editar um post. |
| Barra do admin: limpar o OPcache | "Limpar o OPcache" | ✅ | Só administradores. |
| "Limpar o cache" na lista de posts | Ação na linha do post | ✅ | |
| Painel com status | Dashboard + Mídia | ⭐ | Saúde do ambiente, páginas em cache, teste de tempo de resposta com e sem cache, otimização de imagens. |
| Diagnóstico para suporte | Aba Ferramentas | ⭐ | Inclui hospedagem detectada e plugins com a mesma função. |
| Monitoramento de desempenho (Rocket Insights / `performance_monitoring`) | Teste local de tempo de resposta | 🟡 | O do WP Rocket é um serviço externo; o O² Rocket mede o TTFB com e sem cache no próprio servidor. |
| Capacidades próprias (`rocket_manage_options`, `rocket_purge_cache`...) | Filtro `o2_rocket_capability` | 🟡 | Uma capacidade configurável por filtro em vez de várias capacidades novas. |
| Licença, conta, renovação, analytics, versões beta, avisos de upgrade | — | ➖ | Dependem da conta/servidores do WP Rocket. |
| Desinstalação limpa | `uninstall.php` | ⭐ | Opções, transients, tabelas, crons e post meta de todos os sites, drop-in, `WP_CACHE`, bloco do .htaccess, pasta de cache e de configuração. |
| Avisos quando não consegue gravar (wp-config, .htaccess, cache) | Avisos no painel | ✅ | Com o trecho para copiar. |
| Trecho de configuração para Nginx | Painel | ⭐ | O WP Rocket não oferece (depende de projeto de terceiros). |

## 11. Hospedagens

| Hospedagem (WP Rocket) | O² Rocket | Status | Observação |
| --- | --- | :---: | --- |
| WP Engine, Kinsta, Pressable, WordPress.com, SpinupWP, Savvii, Pressidium | Detectadas | ✅ | Cache de página do plugin desligado (o servidor já faz), aviso no painel, cada limpeza repassada à API da hospedagem. |
| Flywheel, Presslabs | Detectadas | ✅ | Idem. |
| Cloudways, o2switch, WPX Cloud, DreamPress, WP Serveur | Detectadas | ✅ | Varnish ligado sozinho com o endereço/cabeçalhos certos. |
| One.com, PRO ISP | Detectadas | 🟡 | Varnish ligado quando o plugin da hospedagem o ativa; a CDN própria da One.com (usercontent.one) não é configurada sozinha. |
| GoDaddy (Managed WordPress) | Detectada | ✅ | Limpeza pela API Varnish da GoDaddy. |
| SiteGround | Detectada | ✅ | Limpa o SuperCacher junto. |
| Pagely | Detectada | ✅ | |
| LiteSpeed | Detectada | ✅ | Cabeçalho `X-LiteSpeed-Purge`. |
| Nginx (`q` como parâmetro) | — | ➖ | Necessário só em configurações antigas do Nginx que reescrevem para `index.php?q=`; o drop-in usa a URL original. |

## 12. Plugins e temas

| Integração (WP Rocket) | O² Rocket | Status | Observação |
| --- | --- | :---: | --- |
| WooCommerce: carrinho, checkout e conta fora do cache; cookies do carrinho | Automático | ✅ | |
| WooCommerce: limpar produto ao mudar estoque/variação | Automático | ✅ | |
| WooCommerce: parâmetro `v` da geolocalização | Automático | ✅ | |
| WooCommerce: galeria de produtos com atraso de JS | Exclusões automáticas | ✅ | |
| WooCommerce: cache do "cart fragments" com carrinho vazio | — | ❌ | Desde o WooCommerce 7.8 o script só carrega com o mini-carrinho; cachear uma resposta AJAX por cookie traz risco de carrinho errado. |
| Elementor, Beaver Builder, Divi, Avada: limpar ao regenerar CSS, salvar templates e opções do tema | Automático | ✅ | Também templates/partes de tema de blocos, blocos sincronizados e navegação. |
| Temas (Astra, GeneratePress, Flatsome, OceanWP...): limpar ao salvar as opções | Automático | ✅ | |
| Temas e plugins com scripts que quebram com o atraso de JS | 153 temas + 262 plugins | ✅ | Mesmas listas, aplicadas sozinhas. |
| WPML, Polylang, TranslatePress: limpar as traduções e a home de cada idioma | Automático | ✅ | Parâmetro `lang` com cache próprio. |
| Weglot | — | 🟡 | As traduções são limpas pelo próprio cache do Weglot; o O² Rocket limpa a URL original. |
| Idiomas em domínios diferentes (WPML/Polylang) | — | 🟡 | Cache só no domínio principal (a configuração do drop-in é por domínio). |
| Yoast, Rank Math, SEOPress, AIOSEO, The SEO Framework, Jetpack: sitemaps no pré-carregamento | Automático | ✅ | |
| AMP: sem otimizações nas páginas AMP | Automático | ✅ | |
| Autoptimize, Hummingbird, Perfmatters, WP Meteor, W3 Total Cache, WP Super Cache, LiteSpeed Cache, WP Fastest Cache e outros: avisos de conflito | Aviso no painel e no diagnóstico | ✅ | Nome lido do próprio plugin; o cache de outro plugin também aparece como drop-in "de outro plugin". |
| Plugins de celular (WPtouch etc.) forçando cache separado para celular | — | ❌ | Raros hoje; basta ligar "Cache separado para celular". |
| Plugins de cookies/consentimento, anúncios (Termly, Adthrive...) | Exclusões do atraso de JS | ✅ | |
| Wordfence (liberar o IP do serviço de CSS do WP Rocket) | — | ➖ | O CSS é gerado no próprio servidor, sem serviço externo para liberar. |

## 13. Ambiente

| Requisito | O² Rocket | Status | Observação |
| --- | --- | :---: | --- |
| Apache e LiteSpeed (.htaccess: compressão, cache do navegador, entrega do cache) | Suportado | ✅ | Tudo dentro de `<IfModule>`; bloco no topo do arquivo, sem mexer no do WordPress. |
| Nginx | Trecho no painel | ⭐ | Nenhum arquivo gravado; o cache funciona pelo drop-in. |
| Windows/WAMP e Linux | Suportado | ✅ | Testado no Linux; no Windows os caminhos são normalizados (`wp_normalize_path`) e a gravação é atômica, com alternativa quando o Windows bloqueia o `rename`. |
| Sites em subpasta | Suportado | ✅ | Testado no Apache real (cache, .htaccess e entrega pelo servidor). |
| Multisite (subpastas e subdomínios) | Suportado | ⭐ | Configuração e cache isolados por site (testado em subpastas no Apache); .htaccess gerenciado pelo site principal. |
| PHP 7.4+ e WordPress 6.3+ | Suportado | ✅ | Testado no PHP 8.3 com WordPress 7.2-beta + SQLite; o código evita sintaxe exclusiva do PHP 8. |
| Bibliotecas dentro do plugin, sem Composer | matthiasmullie/minify e path-converter (MIT), Bootstrap (MIT) | ✅ | Sem CDN externo no painel. |
