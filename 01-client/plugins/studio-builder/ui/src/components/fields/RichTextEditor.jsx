// RichTextEditor — the `rich_text` field control (lazy-loaded chunk).
//
// Lexical (the approved rich-text engine, KOHEVO-STUDIO-THIRD-PARTY-DECISIONS)
// edits the value; its HTML export is normalized to the canonical allowlist
// (core/richtext.mjs) before it is committed as an operation. The canonical
// value stays an allowlisted HTML string validated by the server — Lexical's
// own JSON state is never stored anywhere.

import { useEffect, useRef } from 'react';
import { LexicalComposer } from '@lexical/react/LexicalComposer';
import { RichTextPlugin } from '@lexical/react/LexicalRichTextPlugin';
import { ContentEditable } from '@lexical/react/LexicalContentEditable';
import { HistoryPlugin } from '@lexical/react/LexicalHistoryPlugin';
import { ListPlugin } from '@lexical/react/LexicalListPlugin';
import { LinkPlugin } from '@lexical/react/LexicalLinkPlugin';
import { OnChangePlugin } from '@lexical/react/LexicalOnChangePlugin';
import { LexicalErrorBoundary } from '@lexical/react/LexicalErrorBoundary';
import { useLexicalComposerContext } from '@lexical/react/LexicalComposerContext';
import { HeadingNode, QuoteNode } from '@lexical/rich-text';
import { ListItemNode, ListNode, INSERT_ORDERED_LIST_COMMAND, INSERT_UNORDERED_LIST_COMMAND } from '@lexical/list';
import { LinkNode, TOGGLE_LINK_COMMAND } from '@lexical/link';
import { $generateHtmlFromNodes, $generateNodesFromDOM } from '@lexical/html';
import { $getRoot, $insertNodes, FORMAT_TEXT_COMMAND } from 'lexical';
import { toAllowedHtml } from '../../core/richtext.mjs';
import { isLikelySafeUrl } from '../../core/fields.mjs';

const THEME = {
  paragraph: 'sbx-rt-p',
  text: { bold: 'sbx-rt-b', italic: 'sbx-rt-i', underline: 'sbx-rt-u', strikethrough: 'sbx-rt-s' },
  list: { ul: 'sbx-rt-ul', ol: 'sbx-rt-ol', listitem: 'sbx-rt-li' },
  link: 'sbx-rt-a',
};

function Toolbar() {
  const [editor] = useLexicalComposerContext();
  const btn = (label, title, onClick) => (
    <button type="button" className="sbx-btn sbx-btn--xs" title={title} aria-label={title} onMouseDown={(e) => e.preventDefault()} onClick={onClick}>{label}</button>
  );
  return (
    <div className="sbx-rt__toolbar" role="toolbar" aria-label="Formatting">
      {btn('B', 'Bold', () => editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'bold'))}
      {btn('I', 'Italic', () => editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'italic'))}
      {btn('U', 'Underline', () => editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'underline'))}
      {btn('•', 'Bulleted list', () => editor.dispatchCommand(INSERT_UNORDERED_LIST_COMMAND, undefined))}
      {btn('1.', 'Numbered list', () => editor.dispatchCommand(INSERT_ORDERED_LIST_COMMAND, undefined))}
      {btn('🔗', 'Link', () => {
        // eslint-disable-next-line no-alert
        const url = window.prompt('Link address (https://…, /path, #anchor, mailto:, tel:)');
        if (url === null) return;
        const clean = url.trim();
        editor.dispatchCommand(TOGGLE_LINK_COMMAND, clean === '' ? null : (isLikelySafeUrl(clean) ? clean : null));
      })}
      {btn('⌫🔗', 'Remove link', () => editor.dispatchCommand(TOGGLE_LINK_COMMAND, null))}
    </div>
  );
}

export default function RichTextEditor({ id, value, onChange }) {
  const lastEmitted = useRef(value);
  const initialConfig = {
    namespace: 'kohevo-studio-rich-text',
    theme: THEME,
    nodes: [HeadingNode, QuoteNode, ListNode, ListItemNode, LinkNode],
    onError: (error) => { throw error; },
    editorState: (editor) => {
      const dom = new DOMParser().parseFromString(value || '<p></p>', 'text/html');
      const nodes = $generateNodesFromDOM(editor, dom);
      $getRoot().clear();
      $getRoot().select();
      $insertNodes(nodes);
    },
  };

  useEffect(() => { lastEmitted.current = value; }, [value]);

  return (
    <div className="sbx-rt">
      <LexicalComposer initialConfig={initialConfig}>
        <Toolbar />
        <RichTextPlugin
          contentEditable={<ContentEditable id={id} className="sbx-rt__editable" aria-multiline="true" />}
          ErrorBoundary={LexicalErrorBoundary}
        />
        <HistoryPlugin />
        <ListPlugin />
        <LinkPlugin validateUrl={isLikelySafeUrl} />
        <OnChangePlugin
          ignoreSelectionChange
          onChange={(_state, editor) => {
            const html = toAllowedHtml(editor.read(() => $generateHtmlFromNodes(editor)));
            if (html !== lastEmitted.current) {
              lastEmitted.current = html;
              onChange(html);
            }
          }}
        />
      </LexicalComposer>
    </div>
  );
}
