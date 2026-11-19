#!/usr/bin/env python3
"""Atualiza as evidências e volta a gerar Word e PDF neste Mac.

Requer os três serviços locais ligados para validar a comunicação.
Não altera o código da aplicação nem as contas locais. Cria uma partida casual de teste que termina por desistência.
"""
from __future__ import annotations
import hashlib
import json
import os
from pathlib import Path
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET
from datetime import datetime
from zoneinfo import ZoneInfo

HERE=Path(__file__).resolve().parent
ROOT=HERE.parent
RUNTIME=Path(os.environ.get('DAD_DOCUMENT_RUNTIME', str(Path.home()/'.cache/codex-runtimes/codex-primary-runtime/dependencies')))
SKILL=Path(os.environ.get('DAD_DOCUMENT_SKILL', str(Path.home()/'.codex/plugins/cache/openai-primary-runtime/documents/26.1030.10813/skills/documents')))
EVIDENCE=HERE/'evidencias'


def capture(args, folder, target):
    result=subprocess.run(args,cwd=ROOT/folder,text=True,capture_output=True)
    (EVIDENCE/target).write_text(result.stdout+result.stderr)
    if result.returncode:
        raise SystemExit(f'Não foi possível concluir {args[0]}. Consulta evidencias/{target}. Para a integração, liga primeiro os três serviços locais.')
    return result.stdout


def main():
    if not (SKILL/'render_docx.py').exists() or not (RUNTIME/'bin/override/soffice').exists():
        raise SystemExit('O runtime de documentos não foi encontrado. Consulta LEIA_ME.md para configurar os caminhos neste computador.')
    EVIDENCE.mkdir(exist_ok=True)
    today=datetime.now(ZoneInfo('Europe/Lisbon')).date().isoformat()
    capture(['php','artisan','test','--compact','--log-junit='+str(EVIDENCE/'testes.xml')],'backend','testes.txt')
    capture(['npm','run','build'],'frontend','compilacao.txt')
    capture(['npm','test'],'realtime','tempo-real.txt')
    capture(['npm','run','test:live'],'realtime','partida-integrada.txt')
    capture(['composer','show','--direct','--format=json'],'backend','composer.json')
    text=capture(['node','scripts/check-local.mjs'],'','integracao.txt')
    (EVIDENCE/'integracao.txt').write_text(f'Data: {today}\nComando: node scripts/check-local.mjs\nCódigo de saída: 0\n'+text)
    meta=json.loads((EVIDENCE/'ambiente.json').read_text())
    meta.update(data=today,php=subprocess.check_output(['php','-r','echo PHP_VERSION;'],text=True),node=subprocess.check_output(['node','--version'],text=True).strip(),npm=subprocess.check_output(['npm','--version'],text=True).strip(),sqlite_php=subprocess.check_output(['php','-r','echo SQLite3::version()["versionString"];'],text=True))
    for folder in ['frontend','realtime']:
        lock=json.loads((ROOT/folder/'package-lock.json').read_text())
        meta[folder]={name:lock['packages']['node_modules/'+name]['version'] for name in ['vue','vue-router','typescript','vite','socket.io','socket.io-client'] if 'node_modules/'+name in lock['packages']}
    with sqlite3.connect('file:'+str(ROOT/'backend/database/database.sqlite')+'?mode=ro',uri=True) as connection:
        meta['schema']={table:[{'name':r[1],'type':r[2],'notnull':bool(r[3]),'pk':bool(r[5])} for r in connection.execute('PRAGMA table_info('+table+')')] for table in ['users','sessions','password_reset_tokens','audit_logs','personal_access_tokens','online_games','rank_entries','tournaments','tournament_participants','wallets','wallet_transactions','wallet_entries','platform_settings']}
    (EVIDENCE/'ambiente.json').write_text(json.dumps(meta,ensure_ascii=False,indent=2))
    suites=[s.attrib for s in ET.parse(EVIDENCE/'testes.xml').getroot().iter('testsuite') if s.get('file')]
    summary={key:sum(int(s.get(key,0)) for s in suites) for key in ['tests','assertions','errors','failures']}
    summary.update(time=sum(float(s.get('time',0)) for s in suites),suites=suites)
    (EVIDENCE/'resumo.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2))
    config=json.loads((HERE/'config.json').read_text());config['data']=today
    (HERE/'config.json').write_text(json.dumps(config,ensure_ascii=False,indent=2))
    manifest={}
    for source in (ROOT/'docs/previews').glob('*.png'):
        shutil.copy2(source,HERE/'imagens'/source.name)
        manifest[source.name]={'origem':str(source.relative_to(ROOT)),'sha256':hashlib.sha256(source.read_bytes()).hexdigest()}
    (EVIDENCE/'capturas.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2))
    pdf_skill=Path(os.environ.get('DAD_PDF_SKILL', str(SKILL.parents[3]/'pdf'/SKILL.parents[1].name/'skills'/'pdf')))
    for output_format, skill in [('docx', SKILL), ('pdf', pdf_skill)]:
        marker=skill/'container_tools/mark_artifact_operation_started.mjs'
        subprocess.run([str(RUNTIME/'node/bin/node'),str(marker),'--operation-kind','edit','--expected-output-count','1','--output-format',output_format],check=True)
    subprocess.run([sys.executable,str(HERE/'gerar_relatorio.py')],check=True)
    qa=Path(tempfile.mkdtemp(prefix='dad-relatorio-qa-',dir='/private/tmp'))
    subprocess.run([sys.executable,str(SKILL/'render_docx.py'),str(HERE/'Relatorio_DAD.docx'),'--output_dir',str(qa),'--emit_pdf'],check=True)
    shutil.copy2(qa/'Relatorio_DAD.pdf',HERE/'Relatorio_DAD.pdf')
    print('Word e PDF atualizados. Revê as imagens renderizadas em '+str(qa))
    print('O conteúdo e as capturas devem ser revistos quando forem acrescentadas funcionalidades.')

if __name__=='__main__':
    main()
