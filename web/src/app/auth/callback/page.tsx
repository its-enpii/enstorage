import { pageTitle } from '@/lib/serverMetadata';
import AuthCallbackClient from './AuthCallbackClient';

export const metadata = pageTitle('auth.callback.title');

export default function Page() {
  return <AuthCallbackClient />;
}