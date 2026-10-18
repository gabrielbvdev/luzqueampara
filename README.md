# Luz que Ampara — versão PHP

Projeto independente em uma página autocontida:

- `index.php`: formulário de homenagem, mural, busca, casas animadas, cartão digital, telão e gravação no banco.
- `lqa_tributes.sql`: cria a tabela única usada pela página.
- `lqa_tributes_migration.sql`: migra o esquema inicial da versão PHP para a tabela e os campos atuais, preservando os registros.
- `homenagens_velario_migration.sql`: migra uma instalação que já usa os campos em português na tabela `lqa_tributes`.
- `homenagens_velario_contacts_migration.sql`: adiciona e-mail e telefone a uma instalação que já usa a tabela `homenagens_velario`.
- Os scripts de migração preservam os registros antigos, deixando seus novos campos de contato sem valor.

## Requisitos

- PHP 8.1 ou superior.
- Extensões `PDO_MYSQL`, `mbstring`, `iconv` e `session`.
- MySQL 8.0.16 ou superior (para validar o `CHECK` de consentimento da tabela).
- HTTPS em produção.

## Instalação

1. Crie um banco de dados MySQL e importe `lqa_tributes.sql`. Se já existir uma tabela de uma versão anterior desta implementação PHP, faça um backup e execute uma única vez o script de migração correspondente ao esquema instalado: `lqa_tributes_migration.sql` para a tabela original com colunas em inglês; `homenagens_velario_migration.sql` para a tabela `lqa_tributes` com colunas em português; ou `homenagens_velario_contacts_migration.sql` para a tabela final `homenagens_velario`.
2. Em produção, preencha `.env` com `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` e `DB_PASSWORD`. O `.env.example` mostra o formato. O PHP carrega esse arquivo somente quando está em produção; variáveis de ambiente `DB_*` definidas no servidor têm precedência. Em localhost, usa a configuração `DB_LOCAL_*` do ambiente, ou os padrões locais. A produção é selecionada automaticamente fora de localhost; configure `APP_ENV=production` explicitamente no servidor, sobretudo se houver proxy reverso.

   O `.env` já está preenchido com o host `177.153.63.83`, porta `3306`, banco `csf_portal` e usuário `csf_portal`. Informe a senha diretamente nesse arquivo no servidor ou configure `DB_PASSWORD` no painel da hospedagem. O `.env` é ignorado pelo Git e o `.htaccess` bloqueia seu acesso direto em servidores Apache. Em outros servidores web, configure também uma regra para impedir acesso HTTP a arquivos `.env`.

   Crie um usuário MySQL dedicado para a aplicação, sem usar `root`, e conceda somente as permissões necessárias (`SELECT` e `INSERT`) na tabela `homenagens_velario`. Não publique nem envie credenciais ao repositório.

3. Publique `index.php` no diretório servido pelo PHP. O SQL e este README podem ficar fora do diretório público.
4. Abra `index.php`. O formulário e o mural fazem parte da mesma página.

Não coloque usuário ou senha diretamente no código nem publique essas credenciais. Configure as variáveis no ambiente em que o PHP é executado (servidor web/PHP-FPM ou configuração local); definir variáveis no terminal só as disponibiliza ao servidor iniciado nesse mesmo terminal. Se alterar as variáveis, reinicie o processo PHP para que ele as receba. Em hospedagem, use o endereço, banco, usuário e senha MySQL informados pelo provedor.

Também é possível testar localmente, a partir desta pasta:

```powershell
$env:DB_LOCAL_HOST = "127.0.0.1"
$env:DB_LOCAL_PORT = "3306"
$env:DB_LOCAL_NAME = "luz_que_ampara"
$env:DB_LOCAL_USER = "usuario"
$env:DB_LOCAL_PASSWORD = "senha"
php -S 127.0.0.1:8000
```

Acesse `http://127.0.0.1:8000/`.

## Dados e privacidade

Todos os registros de velas ficam em `homenagens_velario`. A tabela usa colunas em português, um `id` sequencial `AUTO_INCREMENT` como chave primária e um `uuid` único para identificar as requisições e os registros públicos. A coluna `forma_exibicao` usa os valores `oculto`, `completo` e `iniciais`. A tabela armazena os dados originais da homenagem e os contatos informados; quando houver consentimento, também armazena uma projeção pública em nome completo ou iniciais. O mural consulta apenas essa projeção e não recebe os nomes originais nem os contatos.

## Segurança para publicação

A aplicação usa consultas preparadas com PDO, validação dos campos no servidor, token CSRF e verificação de origem nos envios. Em produção, a ausência de credenciais no `.env` ou nas variáveis `DB_*` impede a conexão. Isso reduz riscos, mas não substitui a configuração segura do servidor: publique somente com HTTPS, mantenha PHP e MySQL atualizados, restrinja o usuário do banco às permissões necessárias e proteja backups e registros, pois nome, e-mail e telefone são dados pessoais armazenados no banco.

Esta versão é independente da aplicação React na raiz do repositório. Não migra nem altera automaticamente os registros da tabela `tributes` existente. O layout de `index.php` acompanha a ordem, textos, paleta, ilustração das casas, varal de homenagens e animações do velário original.
