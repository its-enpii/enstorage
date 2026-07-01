import { pageTitle } from '@/lib/serverMetadata';
import FoldersClient from './FoldersClient';

export const metadata = pageTitle('folders.title');

export default function Page() {
  return <FoldersClient />;
}