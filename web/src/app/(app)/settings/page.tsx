import { pageTitle } from '@/lib/serverMetadata';
import SettingsClient from './SettingsClient';

export const metadata = pageTitle('settings.title');

export default function Page() {
  return <SettingsClient />;
}