# Relatório do projeto

O relatório foi iniciado diretamente nesta pasta. A versão atual documenta a arquitetura local, contas, treino, salas, espectadores, ranking, torneios, carteira e administração, com resultados reais dos testes, capturas da interface e as funcionalidades ainda por implementar.

## Ficheiros principais

- `Relatorio_DAD.pdf`: versão para leitura e revisão.
- `Relatorio_DAD.docx`: versão editável em Word ou aplicação compatível.
- `relatorio.md`: texto-fonte que serve de base à geração.
- `config.json`: identificação do grupo, docentes, data e versão.
- `gerar_relatorio.py`: código que produz o Word, o diagrama e a tabela dos testes.
- `atualizar_relatorio.py`: volta a executar os testes e a compilação, recolhe evidências e gera Word e PDF.
- `imagens/`: capturas originais e diagrama da arquitetura em PNG e SVG.
- `evidencias/`: resultados da execução e resumo em JSON/XML. O ficheiro `texto_gerado.md` contém o texto com os marcadores já substituídos.

## Identificação do grupo

Os nomes, números de aluno, turma, instituição e docentes não foram inventados. Esses campos estão assinalados na capa e podem ser preenchidos em `config.json`. A narrativa e as secções ficam em `relatorio.md`. Alterações feitas apenas no Word não passam automaticamente para o texto-fonte e podem ser substituídas numa nova geração.

## Atualizar no VS Code

Com os serviços locais ligados, executar a tarefa **DAD: Atualizar relatório**, através de **Tasks: Run Task**. O comando usa as ferramentas de documentos já disponíveis neste Mac. As contas locais são preservadas; os testes usam SQLite em memória.

O script atualiza os números dos testes, versões e ficheiros finais. A narrativa deve ser revista quando o projeto ganhar funcionalidades. As capturas são copiadas de `docs/previews/`; não são recriadas automaticamente pelo script. A revisão das páginas renderizadas continua a ser necessária depois de cada atualização.

## Runtime usado neste computador

Python:

```text
/Users/joaoduarte/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/bin/python3
```

Comando direto, a partir da pasta principal:

```sh
/Users/joaoduarte/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/bin/python3 relatorio/atualizar_relatorio.py
```

A geração usa `python-docx`, Pillow e o renderizador de documentos do runtime, com LibreOffice headless e Poppler incluídos. Não exige abrir uma aplicação externa nem carregar os ficheiros para um serviço online. Para outro computador, configurar `DAD_DOCUMENT_RUNTIME` e `DAD_DOCUMENT_SKILL` com os caminhos de um runtime equivalente.

## Antes da entrega final

Preencher a identificação, adequar o relatório ao modelo da unidade curricular quando este for disponibilizado e acrescentar as próximas fases. A versão atual é um relatório de progresso e identifica os requisitos de jogo ainda pendentes, os pagamentos e a publicação. Os emails são locais; não são enviados para caixas de correio reais.

Não foram incluídas palavras-passe, tokens de recuperação, ficheiros `.env`, bases de dados ou o acesso privado guardado em `.local/ADMIN.md`.
