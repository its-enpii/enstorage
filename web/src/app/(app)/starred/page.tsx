import { pageTitle } from '@/lib/serverMetadata';
import StarredClient from './StarredClient';

export const metadata = pageTitle('starred.title');

export default function Page() {
  return <StarredClient />;
}