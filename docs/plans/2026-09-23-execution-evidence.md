# Safety Passwords — execution evidence

## Authority and revision boundary

- Для текущего поручения итоговый отчёт объединяет результаты прерванной и продолжающей её работы, без упоминания восстановления. Это уточнение относится только к этому продолжению, не задаёт правило отчётности для будущих сессий.
- 2026-09-23: пользователь разрешил исполнение плана и принятие исходного PHP diff как отдельной задачи T2.
- План: `2026-09-23-github-issues-and-expiry.md`; база `b02062431f91`; рабочая ветка `delivery/issues-lifecycle-expiry`.
- Поставка: проверенные локальные scoped commits; без push/merge/GitHub writes/release.
- Исходный пользовательский PHP diff: 3 файла, +47/−19; в индексе первоначально только каркас Activation. Сохраняется и доводится в T2, не включается в T1.
- `AGENTS.md` и `.codex/` — исходные untracked файлы пользователя; в поставку автоматически не включать.

## Active batch

- T5/T5b completed in `b43ced0`. T6 review: final frozen diff passed all three version targets; scoped commit next. T7 Stream/independent E1 QA follows; E2 implementation has not started.
- Owner decisions complete: all network accounts, one main-site cron; two bulk modes later, excluding only initiator. No product question currently blocks execution.

| Accepted task | Local commit | Decisive evidence |
| --- | --- | --- |
| T1 isolated runner/CI | `49073f2` | Six successful isolated runs: twice each PHP7.4/WP5.0, PHP7.4/WP6.8, PHP8.2/WP6.8. |
| T2 original activation extraction | `9b99ad4` | Actual missing-callback red, then all three targets green; original user PHP edits integrated separately. |
| T3 expiry UI | `fb9e265` | Actual negative-count red; boundary/overdue/pending/disabled/fallback/no-mutation tests green on all three targets. |
| T4 repeat resets + compatibility | `4f73323` | Actual repeated-reset red; final three targets green including reset/profile/history/mail/CLI, without CLI warning. |
| T5a network policy/scheduler | `0a784e3` | All three targets green including real network Carbon authorization, unassigned accounts, caps, single main-site cron and deactivation. |
| T5b MU bootstrap and transitions | `b43ced0` | All three targets green, including held/expired lock, failed-seed retry, ordinary/MU transitions, coexistence and removal cleanup. |
| T8 bulk design | Plan/evidence | Both modes, network scope, initiator exclusion, bounded job and error contract recorded; implementation waits for E1 QA. |

Original untracked AGENTS/.codex remain outside commits. Remote CI, push/merge/release and GitHub writes have not run. Detailed historical failures and repairs below are superseded by accepted checkpoints where explicitly recorded.

## Initial batch (historical)

- T1 `in_progress`: worker `t1_integration_harness` завершил начальный runner/Compose/fixtures/workflow/readme без product PHP изменений. Static checks: PHP/shell lint, Compose config quiet, отказ remote Docker target. Runtime пока не запускался.
- Root review принял изоляцию по конфигурации, но обнаружил отсутствие надёжного failure propagation в CI lint (`find -exec`); отдельный свежий worker `t1_static_repair` исправляет только CI lint и краткую ссылку на тесты в plugin readme.
- После freeze repair — test_monitor с serial matrix PHP7.4/WP5.0, PHP7.4/WP6.8, PHP8.2/WP6.8, по два последовательных запуска. Root runtime-команды не выполняет.
- Независимый explorer `mu_bulk_decisions` завершил read-only уточнение T5/T8; исправленная область get_users проверена по локальному core, первоначальная гипотеза о глобальном охвате отвергнута.
- T2 ожидает T1; отдельная цель — завершённый перенос lifecycle с сохранением совместимости и отдельным коммитом.

## Initial gates and risks (historical)

- Начальный review подтверждает отсутствие вызова Activation::init; это known failing behavior для T2, не результат T1.
- Для T5 остаются существенные вопросы network lifecycle; для T8 выбраны оба режима, остаются охват/сессии/частичные ошибки и состояние пакетной операции.
- Прямой обычный root Composer install запрещён в проверках: post-install запускает существующий dev/setup.sh с пересозданием БД.
- Новый runner обязан изолировать данные/почту и не выводить пользовательские/секретные значения.
- T1 preflight worker: Docker CLI/Compose 2.26.1, PHP 8.0.30, Composer 2.5.5 доступны; доступ к daemon в обычной песочнице запрещён. Проверку окружения и runtime test_monitor выполнит с требуемым sandbox escalation после freeze.
- T5 read-only clarification: локальный WP_User_Query по умолчанию задаёт blog_id текущего сайта; текущий Controller не охватывает все аккаунты сети. Сетевой охват — отдельное решение пользователя, вопрос отправлен.
- T5 read-only clarification: network Carbon container и carbon_get_theme_option в Settings расходятся по типу хранилища. Нужен runtime regression; существующие сохранённые значения не переносить/перезаписывать молча.
- MU cron bootstrap должен отдельно учитывать capabilities и первичное заполнение истории. Для однократной инициализации предлагается служебный option-marker завершения; schema/область уточняются после решения network scope.
- T8 вопрос пользователю: охват сети и включение инициатора/администраторов. Оба режима уже выбраны. Ожидание ответов не блокирует T1–T4/T6.

## T1 verification boundary

- T1 `review`: оба writer остановлены; CI lint исправлен и проверен на синтетическом успешном/ошибочном PHP и shell; contributor note добавлен в plugin readme.
- Root review: runner запрещает remote Docker, использует явный Compose project и `--env-file /dev/null`, уникальные volumes, readonly source mounts, private DB/WP network и mail guard. Проверено отсутствие изменений начального PHP scope.
- Frozen runner SHA-256 `46fa723b8550302e5aba60364113798acfb3844c682d4f03f097df6ac287f3a2`; compose SHA-256 `21419dea51a7289d1684b0aa36ee214925d9d4a34434d8788de1357f5b2923dc`.
- `t1_runtime_matrix` выполняет согласованную serial ladder; первый failure останавливает её. Коммит и acceptance T1 ожидают результат.

## T1 environment failure and next batch

- Первая команда `bash dev/tests/run.sh php74-wp50`: exit 1 до WordPress, Docker сообщает об исчерпании default IPv4 address pool. Остальные пять запусков не выполнялись; runtime AC не подтверждены.
- Daemon доступен после auto-review; существующий посторонний Compose project не затронут. Runner удалил только собственные временные volumes; рабочее дерево не изменилось.
- Следующий шаг: read-only диагностика Docker subnets/host routes через test_monitor, затем ограниченный repair test-network provisioning. Глобальные сети/настройки Docker не удалять и не изменять.
- Диагностика завершена: 32 Docker networks, default pools практически исчерпаны; остатков spint network нет. Проверены свободные кандидаты 10.254.0.0/24 и 10.254.1.0/24 относительно Docker/host routes. Свежий worker `t1_network_repair` добавляет явные IPAM subnet с проверкой пересечений; существующие сети не изменяются.
- Network repair принят по static review и реальным запускам: PHP7.4.33/WP5.0 и PHP7.4.33/WP6.8 прошли дважды каждый. Все cron/deactivation assertions pass; MU absence воспроизведено как known defect.
- PHP8.2/WP6.8 первый запуск: exit 255 до WP assertions, WP-CLI Extractor исчерпал memory_limit 128MiB; второй не выполнялся. Worktree не изменился, временные ресурсы очищены runner. Свежий worker `t1_memory_repair` повышает только тестовый лимит памяти; после freeze test_monitor завершит runtime gate.
- Memory repair: test-only readonly ini с memory_limit=512M подключён к cli/provision, существующая dev-конфигурация не затронута. Root static review pass; compose SHA-256 `0a3f89f8d26fbdb9eb6e19ddfa5d0a99eaa05a46b2dbfa3a9d6614a5ea76b885`. Test_monitor запустил новую полную последовательную матрицу на этой границе.

## T1 accepted runtime evidence

- На последней frozen boundary последовательно выполнены `bash dev/tests/run.sh php74-wp50` ×2, `bash dev/tests/run.sh php74-wp68` ×2, `bash dev/tests/run.sh php82-wp68` ×2. Все exit 0; реальные версии PHP7.4.33/WP5.0, PHP7.4.33/WP6.8, PHP8.2.33/WP6.8.
- В каждом запуске pass: boot, одно twicedaily event, повторный ensure без дубля, stop, нулевой/включённый интервал, отсутствие попытки письма, реальная ordinary deactivation cleanup. MU отсутствие cron зафиксировано как исходный дефект, не как исправление.
- Runtime около 16–25 секунд на запуск; все собственные ресурсы очищены runner. Before/after git status совпал, frozen checksums совпали; никаких изменений текущей БД/сервисов пользователя.
- Root принимает T1; остаётся scoped local commit. Remote CI не запускался, push не выполнялся. Следующая runnable задача T2: regression второй фазы, затем завершение переноса отдельным product commit.
- T1 доставлена коммитом `49073f2c869b` (`test: add isolated WordPress cron integration checks`). Исходные три PHP-файла и staged каркас Activation не вошли; AGENTS/.codex сохранены untracked. T1 completed, T2 in_progress.
- T2 выполняется двумя ограниченными шагами: сначала worker добавляет lifecycle regression без product changes, test_monitor подтверждает исходное падение; затем свежий worker завершает перенос и test_monitor проверяет исправление. Итоговый product commit отдельный.
- T2 red gate: worker `t2_lifecycle_regression` добавил activation.php + wiring, lint/diff-check pass. `t2_red_verification` выполнил один `bash dev/tests/run.sh php82-wp68`: WP6.8/PHP8.2.33, exit 1 с ожидаемым `FAIL: second-phase cron callback missing`. Временные ресурсы удалены самим runner, worktree неизменен. Root принимает regression; следующий batch — product correction.
- T2 product worker остановлен: Activation::init подключён, старые public lifecycle методы General делегируют новой реализации, callable checks и обе readme обновлены. PHP lint (4 файла) и diff-check pass; root focused review pass. Green runtime пока не запущен.
- Перед новым runtime read-only explorer проверяет происхождение неясного фрагмента вывода предыдущего WP-CLI запуска; значения не копируются в evidence. Доказательств утечки реальных данных нет (только disposable synthetic окружение); no-sensitive-output gate требует уточнения.
- T1 разблокировала T3/T4/T6 (`todo`); они идут после принятия текущей T2 из-за правила одного writer и стабильной verification boundary.
- Output audit: штатный WP-CLI --quiet подавляет informational command/password output; происхождение описанного фрагмента не установлено и утечка не подтверждена. Свежий bounded worker дополнительно подавил оба потока установщика и добавил фиксированную ошибку; shell/stub checks pass, SHA setup `9dda9fb522aeb7bf4a62cf6c831adeebbe9377dd3d369a6346ab00b9ecf52a9f`.
- T2 `review`: `t2_green_verification` выполняет php74-wp50, php74-wp68, php82-wp68 последовательно на исправленном frozen diff. До завершения runtime следующий writer не запускается.
- T2 green gate: все три `bash dev/tests/run.sh <target>` (php74-wp50, php74-wp68, php82-wp68) exit 0. Реальные версии PHP7.4.33/WP5.0, PHP7.4.33/WP6.8, PHP8.2.33/WP6.8. Pass: capability, legacy method availability, deferred callbacks, history seed, один periodic event, стабильный следующий bootstrap, прежние cron/deactivation сценарии. Исходники неизменны во время проверки, test resources очищены runner.
- Root принимает T2 для отдельного коммита. Сохранены существующие границы: при деактивации до второй фазы остаётся одноразовое deferred event (characterization исходного поведения), а MU пока не инициирует cron (T5). Это не заявляется исправленным и не подменяет будущий gate T5.
- T2 доставлена отдельным коммитом `9b99ad4d83b0` (`fix: complete activation lifecycle extraction`). Первоначальные PHP-правки интегрированы; tracked worktree чистый, только исходные AGENTS/.codex untracked. T2 completed; начинается T3 actual-UI regression, затем correction.

## Owner decision: network scope

- Пользователь подтвердил «Вся сеть, один cron»: периодическая политика и обе массовые кнопки действуют на все аккаунты сети, включая не привязанные к сайтам; единственный scheduler на главном сайте.
- Это явное разрешение расширить прежнюю site-member выборку. Существующие user-meta форматы сохраняются; чтение policy согласовать с network Carbon container без молчаливой миграции значений.
- T5 переведена в waiting_dependency (T1/T2/T4); оставшиеся bootstrap-детали уточняются до worker batch. Вопрос о включении инициатора в массовый сброс ещё ожидает ответа.
- Следующий ответ пользователя: массовые операции затрагивают «Всех, кроме инициатора», включая остальных администраторов. T8 `in_progress`: все основные продуктовые решения получены, готовится ограниченный технический контракт; T9 по-прежнему зависит от принятого E1.

## T8 contract — completed design artifact

- Два режима и единый сетевой охват выбраны пользователем; исключается только инициатор. В single-site — все пользователи установки, в multisite — все аккаунты сети, включая unassigned.
- Soft: существующий rp_pre_inited, без рассылки/замены пароля/завершения активной сессии самой командой; переход на существующий reset flow при следующем входе. Hard: существующий rp_inited, WordPress password/reset API, попытка письма; soft не ослабляет hard, hard заменяет soft. last_reset не подделывается массовой командой.
- Уже hard-marked аккаунт пропускается без повторной замены/письма. Ошибка отправки оставляет обязательный сброс, отражается агрегатом; восстановление через штатный WordPress lost-password flow. Автоматического повторного рандомизирования или новой retry-политики нет.
- Пакеты ограничены по размеру, курсор по возрастанию ID и верхняя граница снимка на старте; новые аккаунты после старта не включаются, удаление аккаунтов не сдвигает offset. Служебное состояние операции хранит только необходимые идентификаторы/курсор/режим/агрегаты, без паролей, reset keys и почтовых payload. Никаких этих данных в evidence/logs.
- Один активный job на установку/сеть; атомарный claim/lease и идемпотентный resume. Операции выполняются ограниченными admin POST/AJAX запросами, дополнительный cron не создаётся. Состояние/lock размещаются в options канонического сайта (главный в сети) с атомарным захватом; формат существующих пользовательских метаданных не меняется.
- Каждый POST проверяет nonce, разрешённый mode, job ownership и capability. Single-site сохраняет policy страницы настроек (custom capability либо manage_options); network-wide действия дополнительно требуют manage_network_options. Клиент не определяет инициатора/охват.
- UI: две кнопки с разными последствиями, подтверждение, агрегатный прогресс/возобновление/частичные ошибки. Результат отправки API не называется гарантированной доставкой письма.
- После принятого E1 T9 делится последовательно: **T9a** service/state/handlers + API/runtime tests; **T9b** Carbon UI/JS/progress + interaction/authorization tests + обе readme. Перед запуском каждого worker передаётся полный task contract; DoD G, независимый QA T10 после обоих.
- Обязательные AC T9: all-network/unassigned/admin inclusion, initiator exclusion, soft no mail/password mutation, hard single reset/mail attempt, transitions, nonce/capability/GET/unknown-mode denials, bounded resume/concurrent submission, deletion/addition boundary, mail failure и successful reset cleanup. Exact команды предоставят соответствующие workers для test_monitor.
- Это дизайн и task refinement корневого delivery owner, не реализация. T9 остаётся waiting_dependency на T7; новые продуктовые требования не добавлены.

## Root-authored changes

- T6 final runtime gate PASS: уже пройденный `php74-wp50` дополнен `bash dev/tests/run.sh php74-wp68` exit0 (~26s) и `php82-wp68` exit0 (~34s). Итого все3version targets прошли по9constantprofiles: boolean pairs, integer strings/zero, decimal minimum, actual registration/reset, cron, conflicting saved options и точная Carbon UI indication; полный ordinary/MU/network regression pass. PHP versions7.4.33/8.2.33, WP5.0/6.8. Runtime warnings отсутствуют; четыре frozen hashes/status неизменны, cleanup выполнен, новых artifacts нет. Root принимает T6 для scoped commit; следующий batch T7 Stream fixture и затем независимый E1 QA.

- T6 isolation matrix: `bash dev/tests/run.sh php74-wp50` exit0 (~45–55s), все9constantprofiles и полный ordinary/MU/network набор pass. `php74-wp68` exit1 до тестов: загрузка wordpress-6.8.tar.gz оборвалась с cURL18 (~4min). `php82-wp68` не запускался. Cleanup выполнен, status/diff/hash boundary неизменны. Это transport failure, не product verdict; следующий monitor повторяет только оставшиеся php74-wp68 → php82-wp68 на том же diff, без повторения уже пройденного WP5.

- T6 isolation repair принят: после всех assertions и восстановления options fixture удаляет только созданный ею account через `wp_delete_user` и проверяет успешный результат API. Product и network expected mail count не менялись. PHP/diff checks pass; fixture SHA `ce8940ac`, writer остановлен. Требуется полная matrix на очищающем fixture.

- T6 third runtime: `bash dev/tests/run.sh php74-wp50`, exit1 (~41s). Все девять constant profiles и MU transitions прошли; следующий network scenario failed `main callback attempted unexpected mail count`. Новые fixture accounts с age1/2days попадают под последующую network policy interval1; fresh tests-only repair проверяет и устраняет загрязнение сценариев, сохраняя точный expected mail count. Другие version targets не запускались; cleanup и unchanged hash/status подтверждены.

- T6 reminder fixture repair принят: positive interval3 использует age2days, внутри window и до expiry; zero scenario сохранён. Product не менялся, PHP/diff checks pass, fixture SHA `b34a487f`. Writer остановлен; повторная полная matrix — финальный runtime gate T6.

- T6 second runtime: WP5.0/PHP7.4.33, `bash dev/tests/run.sh php74-wp50` exit1 (~36s). Два constant profiles прошли; третий остановился на `cron zero or positive reminder`. Root trace: fixture задаёт age ровно1day при interval3days/pre-init2days, а существующее сравнение строгое `>`; это не надёжная точка внутри reminder window. Fresh tests-only repair перемещает timestamp внутрь окна, сохраняя product comparator и positive/zero/no-hard-reset assertions. Остальные version targets не запускались, cleanup/status/hash checks pass.

- T6 Carbon fixture repair принят: Carbon `get_name()` добавляет `_`, тогда как registered slug возвращает `get_base_name()`. Fixture теперь использует base name и по-прежнему требует ровно три поля с точными HTML-значениями; product не менялся. PHP lint трёх файлов, bash-n/diff-check pass. Writer остановлен; повторная matrix обязательна.

- T6 first runtime: `bash dev/tests/run.sh php74-wp50`, WP5.0/PHP7.4.33, exit1 (~34s), first constant profile failed `overridden fields not shown`; prior lifecycle/expiry/cron/reset scenarios pass. Другие profiles/targets не запускались; cleanup выполнен, git status/diff неизменны. Fresh worker проверяет Carbon field-name/container expectation и раннюю загрузку constants; UI AC не снимается.

- T6 integration review pass: main checkout Settings и два новых PHP fixtures точно совпадают с reviewed isolated hashes (`253d49d8`, `f8b998c3`, `eb2693aa`); T5b MU setup/readme сохранены. Worker PHP lint/sh-n/diff checks pass; root bash-n/diff-check pass. Writer остановлен. Runtime ещё не выполнен; следующий test_monitor проверяет три цели с девятью constant profiles в каждой.

- T5b доставлена локальным коммитом `b43ced0`; T5 completed. T6 isolated diff принят статически: central boolean/integer normalization, same UI/runtime values, decimal minimum preserved, nine separate WP bootstraps. Fresh worker переносит ровно этот diff поверх T5b, сохраняя MU wiring и readme; runtime T6 ещё не выполнен.

- T5b final gate PASS: `bash dev/tests/run.sh php74-wp50`, `php74-wp68`, `php82-wp68` все exit0 (38/45/36s), WP5.0/PHP7.4.33, WP6.8/PHP7.4.33, WP6.8/PHP8.2.33. Ordinary/deferred/deactivation, MU held/expired lock, failed-history retry, repeats, manual-removal cleanup, mode transitions, network coexistence/history/caps/main cron и прежние scenarios pass. Runtime warnings отсутствуют; runner resources очищены, status/hash boundary неизменны, generated files отсутствуют. Root принимает T5b для scoped commit, затем интеграция T6 из отдельного checkout.

- T5b fixture repair: после физического удаления loader fixture явно доказывает, что прежний cron остался, затем выполняет описанный `wp cron event delete` перед обычной активацией и перед multisite conversion. Исходные deferred/history/one-scheduler assertions сохранены; product не менялся. PHP/shell/diff checks pass. Frozen setup `ed09aa9b`, MU fixture `780a3fff`; повторная matrix проверит полный набор.

- T5b first runtime: `bash dev/tests/run.sh php74-wp50`, WP5.0/PHP7.4.33, exit1 (~38s), first failure `ordinary reactivation scheduled periodic event early`. Ordinary initial/deferred и MU held-lock/failed-history/retry/repeat scenarios до этого прошли. Следующие две цели не запускались; runner cleanup выполнен, source/status неизменны. Требуется bounded investigation/repair перехода MU→ordinary, без ослабления lifecycle AC; затем новая matrix.

- T5b runtime preflight не пройден: Docker Desktop WSL mount и local socket отсутствуют, пригодного endpoint нет. Ни одна команда матрицы не стартовала; source hashes и status неизменны, runtime verdict отсутствует. Запрошено включение Docker на хосте. Gate не принят; frozen T5b сохраняется, независимая T6 может готовиться в отдельном checkout до восстановления среды. Это не исключение из AC и не разрешение считать проверки выполненными.

- T5b detection repair принят статически: root MU loader определяется по include stack и списку `wp_get_mu_plugins`, stack без аргументов; ordinary network загрузка сохраняет deferred phase. Добавлен network MU + ordinary-registration coexistence regression. Writer остановлен; PHP/shell lint и diff-check pass. Frozen Activation `f73f98e4`, network MU fixture `b7dd464d`, setup `b56977f2`. Следующий gate — три runtime цели последовательно, включая held/expired lock, failed-seed retry, mode transitions и network bootstrap.

- T5b static review: atomic claim/CAS, history readback on failed seed, lightweight completed path и removal procedure рассмотрены. Выявлен обязательный repair до runtime: `!did_action('muplugins_loaded')` также истинно при обычной network-plugin загрузке (core загружает network plugins до этого hook), поэтому текущий MU detection нарушает ordinary deferred contract. Fresh worker должен различать реальный root MU loader и regular network load, сохраняя loader из WP_PLUGIN_DIR; существующие network initial assertions не ослаблять.

- T5b upgrade boundary: automatic bootstrap применяется к MU; обычный deferred activation contract сохраняется. Автоматический repair для уже активной ordinary установки без activation hook не добавляется (не был обязательным AC). Для ordinary/network обновления обе readme должны описать однократную повторную активацию, чтобы заполнить полную сетевую историю/права и перенести scheduler; одно посещение settings не заменяет history seed. MU не требует ручной активации. Это операционная граница поставки, не выполненное обновление внешнего сайта.

- T5b/T8 lock refinement: root проверил реализацию add_option и исправил прежнее предположение об атомарности до реализации worker. Canonical options остаются storage; claim использует INSERT IGNORE, stale takeover/release — exact-value CAS. Проверять владение перед completion; старый владелец не снимает чужую lease. Это внутренний concurrency mechanism, без изменения password/auth policy или публичных API. T5b worker подтвердил исправленный контракт; применить его и к T9 jobs.

- T5a доставлена локальным коммитом `0a784e3`; T5a completed, T5b in_progress. MU bootstrap остаётся обязательной частью T5/#7, E1 ещё не принят.

- T5a runtime gate PASS: `bash dev/tests/run.sh php74-wp50`, `php74-wp68`, `php82-wp68` все exit0 на WP5.0/PHP7.4.33, WP6.8/PHP7.4.33, WP6.8/PHP8.2.33. Network getter/conflicting local values, unassigned/subsite accounts, existing/new-site caps, actual Carbon attach/save authorization, canonical cron/duplicate cleanup/subsite no-op/context/deactivation pass; прежние сценарии pass. Runtime warning/fatal отсутствуют, Docker resources очищены, status/diff-stat неизменны. Root принимает T5a для scoped commit; затем T5b MU bootstrap.

- T5a static review принят: network getter/auth condition, all-account queries, main-site scheduler/subsite guard, network caps и real Carbon save fixtures. PHP lint пяти файлов, shell/diff checks pass. Frozen Activation `efb61057`, Controller `6b7432b8`, Cron `419f5201`, Settings `d6588ce4`, setup `3ff42e4e`, network fixture `7e7add47`. Worker завершён; serial three-target matrix проверяет ordinary + добавленный network lifecycle. MU marker/lock/transitions по-прежнему T5b; не считать #7 закрытой.

- T4 доставлена локальным коммитом `4f73323` (`fix: make password reset checks reliable across runtimes`), T4 completed. T5/T5a in_progress: fresh worker реализует network policy/scheduler/caps с isolated fixtures; T5b MU bootstrap ждёт принятого T5a. Исходные AGENTS/.codex вне коммита.

- T4 delivery gate PASS: `bash dev/tests/run.sh php74-wp50`, `php74-wp68`, `php82-wp68` все exit0 (WP5.0/PHP7.4.33; WP6.8/PHP7.4.33; WP6.8/PHP8.2.33), без прежних CLI warning/TypeError. Pass: first/repeat persisted reset, fresh eligible account, mail failure/success/silent valid key, real reset/profile cleanup/history/date, disabled callback и прежние lifecycle/UI/MU checks. Все runner resources удалены, status/hash boundary неизменны. Root принимает T4 для scoped commit; T5a разблокируется после commit.

- T4 CLI repair принят статически: обе result arrays инициализированы перед by-reference checkUsers, unchanged signature/alias/messages; PHP lint/diff-check pass, Safety.php SHA `f564e8d9`. Следующий monitor проверяет окончательный diff на трёх целях, включая отсутствие прежнего CLI warning/TypeError.

- T4 final ladder: php74-wp50 и php74-wp68 exit 0, php82-wp68 exit 1. На всех трёх прошли reset/mail/silent-key/profile/expiry assertions; финальная `wp safety check-users` выявила исходный `implode(null)` в CLI Safety.php:19 (PHP7 warning, PHP8 TypeError). Поэтому CLI AC ещё не принят, несмотря на exit0 двух первых целей. Frozen source/status неизменны, runner cleanup выполнен. Fresh repair — инициализировать by-reference result arrays в CLI без изменения команды/вывода; затем повторить matrix и проверить отсутствие warning.

- T4 fixture repair принят: checks относятся к pending IDs, прочие account reminders допустимы; query IDs нормализованы только внутри assertions, product/CLI типы не меняются. Fixture SHA `31da07e5`, PHP/diff checks pass. Product остаётся Controller `61d843e5`. Повторная полная matrix назначена после стабилизации всех writer changes.

- T4 runtime после legacy repair: WP5.0/PHP7.4.33 (`bash dev/tests/run.sh php74-wp50`) exit 1 на `pending account was reported as a reminder`. До этого первый сброс и повторный guard/no password/no email assertions прошли. Root source review нашёл некорректную общую проверку fixture `$reminded === []`: при interval1 и pre-init window2 другой synthetic admin закономерно получает reminder. Требуется fresh tests-only repair с проверкой membership конкретных pending accounts, без ослабления guard/no-mail/no-mutation AC. Остальные matrix цели не запускались; cleanup выполнен. Рост plan diff во время теста — только root bookkeeping, не product mutation.

- T4 static compatibility repair принят: legacy helper использует get_error_codes и switch_to_locale(get_user_locale), прочие вызовы проверены по core version annotations; lint/diff-check pass. Frozen Controller `61d843e5`, fixture `53b8bcd0`, mailguard `f6543ddb`, setup `91995223`. `t4_compat_green_runtime` запускает полную matrix после исправлений; T4 review.

- T4 legacy fallback review: версия разделения retrieve_password подтверждена [Core5.7](https://make.wordpress.org/core/2021/02/16/login-registration-screens-changes-in-wordpress-5-7/); modern path сохранён, mail/key scenarios добавлены. Root отклонил runtime-ready boundary по двум точно найденным новым несовместимым вызовам: `WP_Error::has_errors()` since5.1 и `switch_to_user_locale()` since6.2 (локальные core docblocks). Fresh минимальный compatibility repair до повторного runtime; PHP lint сам по себе этого не доказывает.

- T4 green остановлен: `bash dev/tests/run.sh php74-wp50`, WP5.0/PHP7.4.33, exit 255 после прежних T1–T3 pass; первый реальный cron-reset вызвал отсутствующую в этом bootstrap `retrieve_password()`. Остальные две цели не запускались; ресурсы runner очищены, source неизменён. Required WP5 compatibility не пройдена; fresh repair должен использовать WordPress reset-key/mail APIs для legacy context, сохраняя modern path, hooks, return/error/skip-email semantics. Снижение заявленной совместимости не разрешено.

- T4 product review pass: persisted guard `'1'`; старому WP выбран profile_update, с WP6.3 сохранён wp_update_user. Формат metadata, cron/CLI/mail-retry policy неизменны; обе readme обновлены. Controller lint/diff-check pass, SHA `7c9bab2a`. `t4_green_runtime` проверяет три PHP/WP цели последовательно; следующий writer ждёт результата.

- T7 verification refinement: root Composer lock уже фиксирует Stream4.0.0; локальный `wordpress/wp-content/plugins/stream/stream.php` и plugin-check присутствуют, но текущий изолированный runner их не включает. Перед E1 QA нужен отдельный bounded fixture/gate реальной Stream registration/write с обезличенным агрегатом (без auth/user payload); не утверждать, что Stream проверен текущей matrix. CI bootstrap для этой проверки должен использовать pinned dependency без root Composer lifecycle scripts.

- T4 actual red подтверждён после sandbox escalation: `bash dev/tests/run.sh php82-wp68`, WP6.8/PHP8.2.33, exit 1, `FAIL: cron reset repeat processed account` на второй загрузке. Первый сброс и предшествующие T1–T3 checks pass. Изолированные ресурсы удалены; source не изменён. Next — fresh worker для canonical persisted flag и profile-cleanup compatibility по required WP5 AC.

- T4 tests worker завершён: fixture `cron-reset-lifecycle.php` SHA `557d754f`, setup `91995223`, PHP/shell/diff checks pass. Первый `t4_red_runtime` runner exit 2 до provisioning: Docker inspection denied, subnet selector fail-closed. Это не product/test verdict; ресурсов не создано, source/status неизменны. Monitor уточняет sandbox escalation и после подтверждённого отсутствия процесса выполняет одну разрешённую попытку с нужным доступом.

- T3 доставлена отдельным локальным коммитом `fb9e265` (`fix: show explicit password expiry states`), T3 completed. T4 in_progress: сначала actual-WP regression persisted flag и двух отдельных проходов, затем минимальный product fix. Исходные AGENTS/.codex по-прежнему исключены.

- T3 green gate принят: `bash dev/tests/run.sh php74-wp50`, `php74-wp68`, `php82-wp68` последовательно exit 0 (WP5.0/PHP7.4.33; WP6.8/PHP7.4.33; WP6.8/PHP8.2.33). Обе UI-поверхности, ordinary/MU stale metadata, window/expiry ±1 second, месяцы просрочки, pending/disabled/fallback/profile scope и отсутствие UI mutations pass; lifecycle/cron regression pass. Runner cleanup выполнен, новых файлов/изменений runtime нет. Root принимает T3 для scoped commit; T4 — следующий batch.

- T3 copy repair принят: notice направляет к форме восстановления без обещания письма, readme уточнён, PHP/PO/diff checks pass. Frozen General SHA `c2d549c2`, expiry fixture `edf96044`. `t3_green_runtime` выполняет три цели php74-wp50 → php74-wp68 → php82-wp68; следующий writer ждёт gate.

- T3 product review: shared read-only calculation и границы срока приняты статически; worker lint обоих PHP/PO/diff-check pass. Перед runtime — свежий copy repair: reset-required notice не должен утверждать доставку письма (mail failure сохраняет флаг); уточнить readme, поскольку профиль показывает info countdown и до семидневного окна. POT standalone msgfmt имеет исходный некорректный placeholder header (подтверждён на HEAD), это не новый сбой русского каталога.

- T4 refinement: локальный core `wp-includes/user.php` помечает hook `wp_update_user` как добавленный в WP6.3, а Controller использует его для profile cleanup. Required AC T4 на WP5.0 должен проверить настоящий profile update, не вручную вызвать отсутствующий core hook. При подтверждённом провале потребуется ограниченная compatibility repair в том же scope; reset/history policy не расширять.

- T5 refinement после read-only mapping: разделить на T5a (единая network policy: чтение Carbon network options, all-account выборки, main-site scheduler/cleanup старых subsite events и network authorization) и T5b (идемпотентная ordinary/MU initialization после готовности Carbon, mode transitions, документация). Оба входят в исходные AC T5; выполнение после T4.
- Для T5 служебные completion/lock хранить в options канонического сайта. **Уточнение после source review:** ни `add_site_option`, ни `add_option` не являются достаточным atomic claim: локальный core option.php:1006 использует upsert при add_option. Для private lock — prepared WPDB INSERT IGNORE/affected-row и owner/value CAS для takeover/release, с согласованным cache handling. Существующие user-meta форматы неизменны; незавершённый setup допускает безопасный повтор после истечения lease.
- T5 loader boundary: существующий fixture `mu-loader.php` из корня mu-plugins подключает main PHP из `WP_PLUGIN_DIR`; поддержанная детекция не может опираться только на физическое расположение main PHP внутри WPMU_PLUGIN_DIR. Carbon getter: `carbon_get_the_network_option($name)` либо `carbon_get_network_option($network_id, $name)` (проверены сигнатуры установленной зависимости). Network container сам по себе наследует manage_options, поэтому дополнительная network capability должна проверяться явно.
- Capabilities T5: сохранить существующий custom cap для administrator roles сайтов; сетевые изменения дополнительно требуют `manage_network_options`. Пользователи без site membership включаются в политику/историю, но не получают административных прав. Loader — стандартный root MU loader, подключающий main PHP; bootstrap работы после Carbon readiness. Физическое удаление MU-файлов не может запустить cleanup и должно быть описано отдельно.

- T3 red gate: `t3_red_runtime` выполнил один `bash dev/tests/run.sh php82-wp68` на HEAD `9b99ad4d83b0` + expiry fixture; WP6.8/PHP8.2.33, exit 1 по ожидаемой проверке `expiry UI months_overdue admin bar has negative days`. Предыдущие lifecycle/cron checks pass; runner удалил только собственные ресурсы, worktree не изменён. Root принимает воспроизведение; следующий batch — T3 UI correction и локализация.

- Только delivery bookkeeping: разрешение исполнения/статус T1 в плане и данный checkpoint; исходники/тесты/процедуры делегированы worker.
- Локальная ветка создана после sandbox escalation; автоматическая проверка разрешила действие. Удалённых действий не было.
