# Diretrizes de Arquitetura: Laravel Volt, Enums, Testes e Acessibilidade

Esta diretriz define as regras obrigatórias para o desenvolvimento do projeto no ecossistema do OFX Tracker.

---

## 1. Desenvolvimento Exclusivo com Laravel Volt

Todas as páginas web e componentes interativos devem ser criados utilizando o **Livewire Volt** (Single File Components).

- É proibida a criação de Controllers ou views Blade separadas para novas páginas.
- Toda a lógica PHP e a marcação HTML/Blade devem coexistir no mesmo arquivo de componente Volt dentro do diretório de páginas.
- Utilize a sintaxe de classe (`new class extends Component`) para componentes com estado ou complexos, facilitando o gerenciamento do ciclo de vida e a tipagem estrita de propriedades.

---

## 2. Domínios de até 10 Opções como PHP Enums

Qualquer domínio de dados (ex: tipos, status, canais, categorias) contendo **10 ou menos opções** deve ser obrigatoriamente implementado como um **Backed PHP Enum** (ex: `enum Canal: string`).

- Sempre declare o tipo do Enum nos models Eloquent usando a propriedade `$casts` (ex: `'tip_associado' => TipoAssociado::class`).
- Os Enums devem implementar um método `label(): string` retornando a descrição formatada e legível de cada opção, e opcionalmente `color(): string` ou `icon(): string` se forem exibidos visualmente na interface.

---

## 3. Cobertura de Testes acima de 70%

Todas as funcionalidades e componentes novos ou modificados devem ser cobertos por testes automatizados.

- A cobertura global do projeto deve ser mantida **acima de 70%**.
- Utilize o framework **Pest** para testes.
- Testes de Feature devem simular a interação do usuário com os componentes Volt (`Livewire::test(...)`), validando ações, estados e toasts disparados.
- Testes Unitários devem cobrir enums, models e helpers.

---

## 4. Acessibilidade de Alto Nível (WCAG / Lighthouse 100)

Toda marcação HTML gerada deve focar em acessibilidade total, alcançando nota 100 em avaliações do Lighthouse ou conformidade total com as diretrizes do WCAG 2.1 AA/AAA.

- **HTML Semântico**: Use as tags de forma adequada (`<main>`, `<nav>`, `<header>`, `<footer >`, `<article>`, `<button>`). Nunca utilize elementos não semânticos para ações que não navegam (use `<button>` em vez de `<a>` ou `<div>` com manipuladores de clique).
- **ARIA**: Insira atributos ARIA apropriados (`aria-label`, `aria-expanded`, `aria-controls`, `aria-hidden`) para tornar dinâmicas e claras as interações do usuário.
- **Teclado**: Garanta que todos os elementos interativos sejam navegáveis via tecla Tab, tenham anéis de foco visíveis e reajam corretamente a Enter e Space.
- **Contraste e Textos**: Mantenha o contraste de texto mínimo de 4.5:1. Forneça rótulos explícitos com `<label>` ou `aria-label` para todos os campos de formulário.

---

## 5. Ambiente de Desenvolvimento (WSL)

O ambiente oficial de desenvolvimento do projeto é o WSL (Windows Subsystem for Linux), especificamente o Ubuntu.

- Todos os comandos de terminal (como `composer`, `npm`, `php artisan`, `pest`) devem ser executados obrigatoriamente de dentro do WSL.
- Para evitar problemas severos de performance de I/O, o repositório do projeto deve residir estritamente no sistema de arquivos do Linux (ex: `/home/n0va1s/...`) e nunca em diretórios montados do Windows (como `/mnt/c/...`).

