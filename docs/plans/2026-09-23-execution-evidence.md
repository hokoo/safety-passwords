# Safety Passwords — execution evidence

## Authority and revision boundary

- 2026-09-23: пользователь разрешил исполнение плана и принятие исходного PHP diff как отдельной задачи T2.
- План: `2026-09-23-github-issues-and-expiry.md`; база `b02062431f91`; рабочая ветка `delivery/issues-lifecycle-expiry`.
- Поставка: проверенные локальные scoped commits; без push/merge/GitHub writes/release.
- Исходный пользовательский PHP diff: 3 файла, +47/−19; в индексе первоначально только каркас Activation. Сохраняется и доводится в T2, не включается в T1.
- `AGENTS.md` и `.codex/` — исходные untracked файлы пользователя; в поставку автоматически не включать.

## Active batch

- T1 `in_progress`: worker `t1_integration_harness` завершил начальный runner/Compose/fixtures/workflow/readme без product PHP изменений. Static checks: PHP/shell lint, Compose config quiet, отказ remote Docker target. Runtime пока не запускался.
- Root review принял изоляцию по конфигурации, но обнаружил отсутствие надёжного failure propagation в CI lint (`find -exec`); отдельный свежий worker `t1_static_repair` исправляет только CI lint и краткую ссылку на тесты в plugin readme.
- После freeze repair — test_monitor с serial matrix PHP7.4/WP5.0, PHP7.4/WP6.8, PHP8.2/WP6.8, по два последовательных запуска. Root runtime-команды не выполняет.
- Независимый explorer `mu_bulk_decisions` завершил read-only уточнение T5/T8; исправленная область get_users проверена по локальному core, первоначальная гипотеза о глобальном охвате отвергнута.
- T2 ожидает T1; отдельная цель — завершённый перенос lifecycle с сохранением совместимости и отдельным коммитом.

## Gates and risks

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

- T3 green gate принят: `bash dev/tests/run.sh php74-wp50`, `php74-wp68`, `php82-wp68` последовательно exit 0 (WP5.0/PHP7.4.33; WP6.8/PHP7.4.33; WP6.8/PHP8.2.33). Обе UI-поверхности, ordinary/MU stale metadata, window/expiry ±1 second, месяцы просрочки, pending/disabled/fallback/profile scope и отсутствие UI mutations pass; lifecycle/cron regression pass. Runner cleanup выполнен, новых файлов/изменений runtime нет. Root принимает T3 для scoped commit; T4 — следующий batch.

- T3 copy repair принят: notice направляет к форме восстановления без обещания письма, readme уточнён, PHP/PO/diff checks pass. Frozen General SHA `c2d549c2`, expiry fixture `edf96044`. `t3_green_runtime` выполняет три цели php74-wp50 → php74-wp68 → php82-wp68; следующий writer ждёт gate.

- T3 product review: shared read-only calculation и границы срока приняты статически; worker lint обоих PHP/PO/diff-check pass. Перед runtime — свежий copy repair: reset-required notice не должен утверждать доставку письма (mail failure сохраняет флаг); уточнить readme, поскольку профиль показывает info countdown и до семидневного окна. POT standalone msgfmt имеет исходный некорректный placeholder header (подтверждён на HEAD), это не новый сбой русского каталога.

- T4 refinement: локальный core `wp-includes/user.php` помечает hook `wp_update_user` как добавленный в WP6.3, а Controller использует его для profile cleanup. Required AC T4 на WP5.0 должен проверить настоящий profile update, не вручную вызвать отсутствующий core hook. При подтверждённом провале потребуется ограниченная compatibility repair в том же scope; reset/history policy не расширять.

- T5 refinement после read-only mapping: разделить на T5a (единая network policy: чтение Carbon network options, all-account выборки, main-site scheduler/cleanup старых subsite events и network authorization) и T5b (идемпотентная ordinary/MU initialization после готовности Carbon, mode transitions, документация). Оба входят в исходные AC T5; выполнение после T4.
- Для T5 служебные completion/lock хранить в options канонического сайта; уникальность `option_name` позволяет атомарный `add_option`, не считать `add_site_option` атомарным lock. Существующие user-meta форматы неизменны. Повторный bootstrap не добавляет историю повторно; незавершённый setup допускает безопасный повтор после истечения lease.
- Capabilities T5: сохранить существующий custom cap для administrator roles сайтов; сетевые изменения дополнительно требуют `manage_network_options`. Пользователи без site membership включаются в политику/историю, но не получают административных прав. Loader — стандартный root MU loader, подключающий main PHP; bootstrap работы после Carbon readiness. Физическое удаление MU-файлов не может запустить cleanup и должно быть описано отдельно.

- T3 red gate: `t3_red_runtime` выполнил один `bash dev/tests/run.sh php82-wp68` на HEAD `9b99ad4d83b0` + expiry fixture; WP6.8/PHP8.2.33, exit 1 по ожидаемой проверке `expiry UI months_overdue admin bar has negative days`. Предыдущие lifecycle/cron checks pass; runner удалил только собственные ресурсы, worktree не изменён. Root принимает воспроизведение; следующий batch — T3 UI correction и локализация.

- Только delivery bookkeeping: разрешение исполнения/статус T1 в плане и данный checkpoint; исходники/тесты/процедуры делегированы worker.
- Локальная ветка создана после sandbox escalation; автоматическая проверка разрешила действие. Удалённых действий не было.
